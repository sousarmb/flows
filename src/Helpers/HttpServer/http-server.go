package main

import (
	"bufio"
	"context"
	"encoding/json"
	"flag"
	"io"
	"log"
	"mime"
	"net"
	"net/http"
	"os"
	"slices"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	sanitize "flows.local/http-server/sanitize"
)

/* ================= CONFIG ================= */

var port *int
var commandPipePath *string
var serverInstanceUID *string

// Used for timeoutReadExternalProcess calculation
var temp1 *int
var timeoutReadExternalProcess time.Duration

/* ================= DATA ================= */

// Idea?
// type AcceptedResponse struct {
// 	// Message    string `json:"message"`    // "Request accepted. Proceeding with process."
// 	// MonitorURL string `json:"monitorUrl"` // "http://example.com/tasks/123/status"
// 	TaskID string `json:"task_id"`
// }

type commandMessage struct {
	Command           string   `json:"command"`
	Path              string   `json:"path"`
	PipeFrom          string   `json:"pipe_from"`
	PipeTo            string   `json:"pipe_to"`
	ExternalProcessID string   `json:"external_process_id"`
	AllowedMethods    []string `json:"allowed_methods"`
	Timeout           int      `json:"timeout"`
}

type requestMessage struct {
	Method      string              `json:"method"`
	Path        string              `json:"path"`
	Headers     map[string][]string `json:"headers"`
	Body        any                 `json:"body"`
	ContentType string              `json:"contentType"`
	Files       map[string]string   `json:"files"`
	Cookies     []*http.Cookie      `json:"cookies"`
	InstanceUID string              `json:"instance_uid"` // Server instance unique identifier
}

type responseMessage struct {
	Ok          bool   `json:"ok"`           // External process signal, FALSE => respond with 400, TRUE => 200
	Code        int    `json:"code"`         // Reserved for custom code
	Status      string `json:"status"`       // Reserved for custom status message
	Message     string `json:"message"`      // Reserved for custom message
	InstanceUID string `json:"instance_uid"` // External process unique identifier
}

type logLine struct {
	Operation         string `json:"operation"`
	Status            string `json:"status"`
	Reason            string `json:"reason"`
	Resource          string `json:"resource"`
	ServerInstance    string `json:"server_instance"`
	ExternalProcessID string `json:"external_process_id"`
}

/* ================= MUX ================= */

type handlerEntry struct {
	enabled           atomic.Bool
	handler           http.Handler
	handling          atomic.Bool // external process is evaluating the request
	handled           atomic.Bool // external process has handled the request
	pipeTo            string      // this HTTP server WRITES to this stream
	pipeFrom          string      // this HTTP server READS from this stream
	externalProcessID string
	allowedMethods    []string
	timeout           int64 // after this time the handler is no longer considered valid
}

type DynamicMux struct {
	mu       sync.RWMutex
	handlers map[string]*handlerEntry
}

func NewDynamicMux() *DynamicMux {
	return &DynamicMux{
		handlers: make(map[string]*handlerEntry),
	}
}

func (m *DynamicMux) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	m.mu.RLock()
	entry, ok := m.handlers[r.URL.Path]
	m.mu.RUnlock()

	if !ok || !entry.enabled.Load() {
		http.NotFound(w, r)
		return
	}

	entry.handler.ServeHTTP(w, r)
}

/* ================= PIPE IO ================= */

// Send message to external process.
func writePipeMessage(message []byte, pipe *os.File) error {
	_, err := pipe.Write(append(message, []byte("\n")...))
	return err
}

/* Read (JSON encoded) message from external process and unmarshal it.
 * The resulting message determines the response status to send the client.
 */
func readPipeMessage(pipe *os.File, entry *handlerEntry) responseMessage {
	emptyResponse := responseMessage{false, -1, "fail", "Empty response from external process", entry.externalProcessID}
	badResponse := responseMessage{false, -2, "fail", "Bad response from external process", entry.externalProcessID}
	unmarshalError := responseMessage{false, -3, "fail", "Unmarshal error", entry.externalProcessID}
	timeoutWaitingForExternalProcess := responseMessage{false, -4, "fail", "Timeout waiting for external process", entry.externalProcessID}

	ch := make(chan string, 1)
	errCh := make(chan bool, 1)

	scanner := bufio.NewScanner(pipe)
	go func() {
		if !scanner.Scan() {
			errCh <- true
			return
		}

		ch <- scanner.Text()
	}()
	// Block until something happens
	select {
	case <-time.After(timeoutReadExternalProcess):
		return timeoutWaitingForExternalProcess

	case <-errCh:
		return emptyResponse

	case line := <-ch:
		line = strings.TrimSpace(line)
		if !json.Valid([]byte(line)) {
			return badResponse
		}

		var message responseMessage
		if err := json.Unmarshal([]byte(line), &message); err != nil {
			return unmarshalError
		}

		return message
	}
}

/* ================= REGISTER ================= */

func (m *DynamicMux) Register(message commandMessage) {
	if len(message.AllowedMethods) == 0 {
		message.AllowedMethods = []string{"GET", "POST", "PUT", "PATCH", "DELETE"}
	} else if slices.Contains(message.AllowedMethods, "CONNECT") || slices.Contains(message.AllowedMethods, "HEAD") || slices.Contains(message.AllowedMethods, "OPTIONS") || slices.Contains(message.AllowedMethods, "TRACE") {
		logThis(logLine{"register handler", "fail", "invalid methods", message.Path, *serverInstanceUID, message.ExternalProcessID})
		return
	}

	entry := &handlerEntry{}
	entry.enabled.Store(true)
	entry.handling.Store(false)
	entry.handled.Store(false)
	entry.pipeFrom = message.PipeTo // mirrored
	entry.pipeTo = message.PipeFrom // mirrored
	entry.externalProcessID = message.ExternalProcessID
	entry.allowedMethods = message.AllowedMethods
	entry.timeout = time.Now().Unix() + int64(message.Timeout)
	// Define handler function
	entry.handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !entry.enabled.Load() { // Fail if handled previously or about to deregister
			http.NotFound(w, r)
			return
		} else if entry.handling.Load() { // Fail if already handling
			w.WriteHeader(http.StatusLocked)
			return
		}

		// Allow Preflight request
		// if r.Method == http.MethodOptions {
		// 	handleOptionsRequest(w, r)
		// 	return
		// }

		// Fail if method not supported
		if !slices.Contains(entry.allowedMethods, r.Method) {
			w.WriteHeader(http.StatusMethodNotAllowed)
			return
		}
		// Mark as being handled
		entry.handling.Store(true)
		// Open pipes to communicate with external process
		pipeFrom, err := os.OpenFile(entry.pipeFrom, os.O_RDONLY, 0664)
		if err != nil {
			w.WriteHeader(http.StatusInternalServerError)
			logThis(logLine{"pipe open", "fail", err.Error(), entry.pipeFrom, *serverInstanceUID, message.ExternalProcessID})
			return
		}
		// External process already opened pipe
		defer pipeFrom.Close()
		pipeTo, err := os.OpenFile(entry.pipeTo, os.O_WRONLY, 0664)
		if err != nil {
			w.WriteHeader(http.StatusInternalServerError)
			logThis(logLine{"pipe open", "fail", err.Error(), entry.pipeTo, *serverInstanceUID, message.ExternalProcessID})
			return
		}
		// External process already opened pipe
		defer pipeTo.Close()
		// Process request
		message := requestMessage{
			Method:      r.Method,
			Path:        r.URL.EscapedPath(),
			Headers:     r.Header,
			Cookies:     r.Cookies(),
			InstanceUID: *serverInstanceUID,
		}
		// Handle request (... and send message to external process)
		switch r.Method {
		case http.MethodGet, http.MethodDelete:
			handleGetOrDeleteMethod(pipeFrom, pipeTo, w, entry, &message)
		case http.MethodPatch, http.MethodPost, http.MethodPut:
			handlePostOrPutOrPatchMethod(pipeFrom, pipeTo, w, r, entry, &message)
		}
	})

	m.mu.Lock()
	m.handlers[message.Path] = entry
	m.mu.Unlock()
	logThis(logLine{"register handler", "ok", "", message.Path, *serverInstanceUID, message.ExternalProcessID})
}

/* Command to deregister a resource */
func (m *DynamicMux) Deregister(message commandMessage) {
	m.mu.Lock()
	defer m.mu.Unlock()
	entry, ok := m.handlers[message.Path]
	if !ok {
		logThis(logLine{"deregister", "fail", "resource not found", message.Path, *serverInstanceUID, message.ExternalProcessID})
		return
	}

	if message.ExternalProcessID != entry.externalProcessID {
		// Cannot unregister other process resource
		logThis(logLine{"deregister", "fail", "resource owned by " + entry.externalProcessID, message.Path, *serverInstanceUID, message.ExternalProcessID})
		return
	}

	entry.enabled.Store(false)
	// Housekeeping
	_, err := os.Stat(entry.pipeFrom)
	if err == nil {
		os.Remove(entry.pipeFrom)
		logThis(logLine{"remove pipe", "ok", "deregister", entry.pipeFrom, *serverInstanceUID, entry.externalProcessID})
	}

	_, err = os.Stat(entry.pipeTo)
	if err == nil {
		os.Remove(entry.pipeTo)
		logThis(logLine{"remove pipe", "ok", "deregister", entry.pipeFrom, *serverInstanceUID, entry.externalProcessID})
	}

	temp1 := message.Path
	temp2 := message.ExternalProcessID
	delete(m.handlers, message.Path)
	logThis(logLine{"deregister", "ok", "", temp1, *serverInstanceUID, temp2})
}

/* ================= FUNCTIONS ================= */

// Idea?
// func handleOptionsRequest(w http.ResponseWriter, r *http.Request) {
// 	w.WriteHeader(http.StatusNoContent)
// 	w.Header().Set("Access-Control-Allow-Origin", "*")
// 	w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
// 	w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization")
// 	w.Header().Set("Access-Control-Allow-Credentials", "true")
// 	w.Header().Set("Access-Control-Max-Age", "86400") // 24 hours
// 	// Additional headers
// 	w.Header().Set("Accept", "application/x-www-form-urlencoded, multipart/form-data, application/json")
// }

func logThis(line logLine) {
	j, err := json.Marshal(line)
	if err != nil {
		panic("Cannot write log line")
	}

	log.Println(string(j))
}

/* Creates a file in the os temporary directory that is meant to be used by the external process that's
 * handling the HTTP request content.
 */
func createFileForExternalProcess() (*os.File, error) {
	f, err := os.CreateTemp(os.TempDir(), "flows-http-request-file-")
	if err != nil {
		return nil, err
	}

	return f, nil
}

/* Clean up in case of errors before sending message to external process.
 * Delete files in the os temporary directory that is meant to be used by the external process that's
 * handling the HTTP request content.
 */
func deleteFileForExternalProcess(files map[string]string, entry *handlerEntry) {
	for _, filePath := range files {
		_, err := os.Stat(filePath)
		if err != nil {
			// File previously removed ?!
			logThis(logLine{"stat file", "fail", err.Error(), filePath, *serverInstanceUID, entry.externalProcessID})
			continue
		}

		err = os.Remove(filePath)
		if err != nil {
			// File previously removed ?!
			logThis(logLine{"remove file", "fail", err.Error(), filePath, *serverInstanceUID, entry.externalProcessID})
			continue
		}
	}
}

/* ================= HANDLERS ================= */

func handlePostOrPutOrPatchMethod(pipeFrom *os.File, pipeTo *os.File, w http.ResponseWriter, r *http.Request, entry *handlerEntry, message *requestMessage) {
	// Prepare message for external process
	ct, _, err := mime.ParseMediaType(r.Header.Get("Content-Type"))
	if err != nil {
		w.WriteHeader(http.StatusBadRequest)
		return
	}

	message.ContentType = ct
	maxBodySize := int64(16 << 20) // 16 MB;
	r.Body = http.MaxBytesReader(w, r.Body, maxBodySize)

	// cotinuar aqui io.LimitReader(r.Body, 8 << 20)
	// pagar Sandra

	// Read body fields
	switch message.ContentType {
	case "application/json":
		// Read raw body
		body, err := io.ReadAll(r.Body)
		if err != nil {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusBadRequest)
			rm := responseMessage{false, 400, "fail", "Request max size is 16 MB", entry.externalProcessID}
			jm, _ := json.Marshal(rm)
			w.Write(jm)
			entry.handling.Store(false)
			return
		}
		// Sanitize raw body
		body = sanitize.StripInvisibleBytes(body)
		// Parse JSON body
		if !json.Valid(body) {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusBadRequest)
			rm := responseMessage{false, 400, "fail", "Invalid JSON", entry.externalProcessID}
			jm, _ := json.Marshal(rm)
			w.Write(jm)
			entry.handling.Store(false)
			return
		}
		// Set message body
		message.Body = string(body)

	case "application/x-www-form-urlencoded":
		err := r.ParseForm()
		if err != nil {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusBadRequest)
			rm := responseMessage{false, 400, "fail", "Request max size is 16 MB", entry.externalProcessID}
			jm, _ := json.Marshal(rm)
			w.Write(jm)
			entry.handling.Store(false)
			return
		}
		if r.Form != nil && message.Body == nil {
			/* Only concrete types (e.g., map[string]string) support indexing.
			 * Fix by doing a type assertion (or switching on the type) and ensuring message.Body
			 * is a map before writing into it.
			 */
			mb := make(map[string]string)
			for key, values := range r.Form {
				if len(values) > 0 {
					mb[key] = sanitize.StripInvisibleRunes(values[0])
				}
			}
			if len(mb) > 0 {
				message.Body = mb
			}
		}

	case "multipart/form-data":
		err := r.ParseMultipartForm(maxBodySize)
		if err != nil {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusBadRequest)
			rm := responseMessage{false, 400, "fail", "Request max size is 16 MB", entry.externalProcessID}
			jm, _ := json.Marshal(rm)
			w.Write(jm)
			entry.handling.Store(false)
			return
		}
		if r.MultipartForm != nil && r.MultipartForm.Value != nil {
			mb := make(map[string]string)
			for key, values := range r.MultipartForm.Value {
				if len(values) > 0 {
					mb[key] = sanitize.StripInvisibleRunes(values[0])
				}
			}
			if len(mb) > 0 {
				message.Body = mb
			}
		}
		if r.MultipartForm != nil && r.MultipartForm.File != nil {
			message.Files = make(map[string]string)
			for _, fhs := range r.MultipartForm.File {
				for _, fh := range fhs {
					file, err := fh.Open()
					if err != nil {
						w.Header().Set("Content-Type", "application/json")
						w.WriteHeader(http.StatusBadRequest)
						rm := responseMessage{false, 400, "fail", "Failed to open file " + fh.Filename, entry.externalProcessID}
						jm, _ := json.Marshal(rm)
						w.Write(jm)
						entry.handling.Store(false)
						return
					}
					// Create file for external process with uploaded form file content
					fileForExternalProcess, err := createFileForExternalProcess()
					if err != nil {
						file.Close()
						// Delete previously created files
						defer deleteFileForExternalProcess(message.Files, entry)
						// Send response
						w.Header().Set("Content-Type", "application/json")
						w.WriteHeader(http.StatusInternalServerError)
						rm := responseMessage{false, 500, "fail", "Failed to create file for external process", entry.externalProcessID}
						jm, _ := json.Marshal(rm)
						w.Write(jm)
						entry.handling.Store(false)
						return
					}
					// Copy file for external process use
					_, err = io.Copy(fileForExternalProcess, file)
					fileForExternalProcess.Close()
					file.Close()
					if err != nil {
						// Delete previously created files
						message.Files[sanitize.StripInvisibleRunes(fh.Filename)] = fileForExternalProcess.Name()
						defer deleteFileForExternalProcess(message.Files, entry)
						// Send response
						w.Header().Set("Content-Type", "application/json")
						w.WriteHeader(http.StatusInternalServerError)
						rm := responseMessage{false, 500, "fail", "Failed to save file " + fh.Filename, entry.externalProcessID}
						jm, _ := json.Marshal(rm)
						w.Write(jm)
						entry.handling.Store(false)
						return
					}
					// Add to message
					message.Files[sanitize.StripInvisibleRunes(fh.Filename)] = fileForExternalProcess.Name()
				}
			}
		}
	default:
		w.WriteHeader(http.StatusBadRequest)
		entry.handling.Store(false)
		return
	}
	// Prepare message for external process
	jm, err := json.Marshal(message)
	if err != nil {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusInternalServerError)
		rm := responseMessage{false, 500, "fail", "Cannot serialize message", entry.externalProcessID}
		jm, _ := json.Marshal(rm)
		w.Write(jm)
		entry.handling.Store(false)
		return
	}
	// Send message to external process
	err = writePipeMessage(jm, pipeTo)
	if err != nil {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusInternalServerError)
		rm := responseMessage{false, 500, "fail", "Failed to send to external process", entry.externalProcessID}
		jm, _ := json.Marshal(rm)
		w.Write(jm)
		entry.handling.Store(false)
		logThis(logLine{"pipe write", "fail", err.Error(), entry.pipeFrom, *serverInstanceUID, entry.externalProcessID})
	}
	// Read external process response (valid or not?)
	response := readPipeMessage(pipeFrom, entry)
	jm, _ = json.Marshal(response)
	w.Header().Set("Content-Type", "application/json")
	if response.Ok {
		// Send accepted response, flow is going to resume
		w.WriteHeader(http.StatusAccepted)
	} else {
		w.WriteHeader(http.StatusBadRequest)
	}
	// Send response message from external process
	w.Write(jm)
	if response.Ok {
		// Mark handler as disabled and handled
		entry.enabled.Store(false)
		entry.handled.Store(true)
		logThis(logLine{"handle resource", "ok", "", message.Path, *serverInstanceUID, entry.externalProcessID})
	} else {
		// Release handler and mark as not handled
		entry.handling.Store(false)
	}
}

func handleGetOrDeleteMethod(pipeFrom *os.File, pipeTo *os.File, w http.ResponseWriter, entry *handlerEntry, message *requestMessage) {
	message.Body = nil
	js, _ := json.Marshal(message)
	err := writePipeMessage(js, pipeTo)
	if err != nil {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusInternalServerError)
		rm := responseMessage{false, 500, "fail", "Failed to send to external process", entry.externalProcessID}
		js, _ = json.Marshal(rm)
		w.Write(js)
		entry.handling.Store(false)
		logThis(logLine{"pipe write", "fail", err.Error(), entry.pipeFrom, *serverInstanceUID, entry.externalProcessID})
		return
	}

	response := readPipeMessage(pipeFrom, entry)
	js, _ = json.Marshal(response)
	w.Header().Set("Content-Type", "application/json")
	if response.Ok {
		// Send accepted response, flow is going to resume
		w.WriteHeader(http.StatusAccepted)
	} else {
		w.WriteHeader(http.StatusBadRequest)
	}
	// Send response message from external process
	w.Write(js)
	if response.Ok {
		// Mark handler as disabled and handled
		entry.enabled.Store(false)
		entry.handled.Store(true)
		logThis(logLine{"handle resource", "ok", "", message.Path, *serverInstanceUID, entry.externalProcessID})
	} else {
		// Release handler and mark as not handled
		entry.handling.Store(false)
	}
}

/* ================= ECHO ================= */

func (m *DynamicMux) echo() {
	path := "/echo"
	entry := &handlerEntry{}
	entry.enabled.Store(true)
	entry.handling.Store(false)
	entry.handled.Store(false)
	// entry.pipeFrom = ""
	// entry.pipeTo = ""
	// entry.externalProcessID = ""
	entry.allowedMethods = []string{"GET"}
	entry.timeout = time.Now().Unix()
	// Define handler function
	entry.handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		echoData := []string{"echo", time.Now().Format(time.DateTime), *serverInstanceUID}
		echo, _ := json.Marshal(echoData)
		w.Header().Set("Content-Type", "application/json")
		w.Write(echo)
	})
	m.mu.Lock()
	m.handlers[path] = entry
	m.mu.Unlock()
	logThis(logLine{"register handler", "ok", "", path, *serverInstanceUID, ""})
}

/* ================= WATCHERS ================= */

func watchHandlers(parent context.Context, mux *DynamicMux, server *http.Server, cancel context.CancelFunc) {
	ticker := time.NewTicker(300 * time.Millisecond)
	defer ticker.Stop()

	for {
		select {
		case <-parent.Done():
			logThis(logLine{"context done", "ok", "", "func watchHandlers()", *serverInstanceUID, ""})
			return

		case <-ticker.C:
			mux.mu.Lock()
			for k, v := range mux.handlers {
				if k == "/echo" {
					// Always present
					continue
				}
				if (!v.enabled.Load() && v.handled.Load()) || v.timeout < time.Now().Unix() {
					reason := ""
					if !v.enabled.Load() && v.handled.Load() {
						reason = "handled"
					} else if v.timeout < time.Now().Unix() {
						reason = "timeout"
					}

					os.Remove(v.pipeFrom)
					logThis(logLine{"remove pipe", "ok", reason, v.pipeFrom, *serverInstanceUID, v.externalProcessID})

					os.Remove(v.pipeTo)
					logThis(logLine{"remove pipe", "ok", reason, v.pipeTo, *serverInstanceUID, v.externalProcessID})

					externalProcessID := v.externalProcessID
					delete(mux.handlers, k)
					logThis(logLine{"remove resource", "ok", reason, k, *serverInstanceUID, externalProcessID})
				}
			}

			remaining := len(mux.handlers) - 1 // -1 is /echo
			mux.mu.Unlock()

			if remaining <= 0 {
				/*
				 * Create new context to prevent immediate shutdown:
				 * If parent context is somehow already canceled, 5 seconds timeout won't be respected
				 */
				shutdownCtx, cancelShutdown := context.WithTimeout(context.Background(), 5*time.Second)
				server.Shutdown(shutdownCtx)
				cancelShutdown()
				logThis(logLine{"shutdown", "ok", "no handlers available", "", *serverInstanceUID, ""})
				// Cancel parent context, used in watchPipe() routine
				cancel()
				// After watchPipe() exits it's safe to remove command pipe
				if _, err := os.Stat(*commandPipePath); err == nil {
					os.Remove(*commandPipePath)
					logThis(logLine{"remove pipe", "ok", "shutdown", *commandPipePath, *serverInstanceUID, ""})
				}

				return
			}
		}
	}
}

func watchPipe(parent context.Context, mux *DynamicMux, commandPipePath *string) {
	ticker := time.NewTicker(100 * time.Millisecond)
	defer ticker.Stop()
	for {
		select {
		case <-parent.Done():
			logThis(logLine{"context done", "ok", "", "func watchPipe()", *serverInstanceUID, ""})
			return

		case <-ticker.C:
			f, err := os.OpenFile(*commandPipePath, os.O_RDWR, 0664)
			if err != nil {
				logThis(logLine{"pipe open", "fail", err.Error(), *commandPipePath, *serverInstanceUID, ""})
				return
			}

			scanner := bufio.NewScanner(f)
			for scanner.Scan() {
				line := scanner.Text()
				if false == json.Valid([]byte(line)) {
					logThis(logLine{"validate json", "fail", "invalid json", *commandPipePath, *serverInstanceUID, ""})
					continue
				}

				var message commandMessage
				if err := json.Unmarshal([]byte(line), &message); err != nil {
					logThis(logLine{"unmarshal json", "fail", err.Error(), *commandPipePath, *serverInstanceUID, ""})
					continue
				}
				switch message.Command {
				case "REGISTER":
					mux.Register(message)
				case "DEREGISTER":
					mux.Deregister(message)
				default:
					logThis(logLine{"run command", "fail", "invalid command", *commandPipePath, *serverInstanceUID, message.ExternalProcessID})
				}
			}
			f.Close()
		}
	}
}

/* ================= MAIN ================= */

func main() {
	port = flag.Int("port", 8888, "The port the server listen for HTTP requests")
	commandPipePath = flag.String("command-pipe-path", "/tmp/flows-http-server", "The pipe where the server receives commands")
	serverInstanceUID = flag.String("server-instance-uid", "", "This server unique identifier")

	temp1 = flag.Int("timeout-read-external-process", 30, "How long (in seconds) to wait for external process process")
	timeoutReadExternalProcess = time.Duration(*temp1) * time.Second

	help := flag.Bool("help", false, "Show this help")
	flag.Parse()
	if *help {
		flag.Usage()
		return
	}
	if *serverInstanceUID == "" {
		flag.Usage()
		return
	}

	log.SetFlags(log.LstdFlags | log.Lshortfile)
	mux := NewDynamicMux()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	// Register /echo resource
	mux.echo()
	go watchPipe(ctx, mux, commandPipePath)
	addr := ":" + strconv.Itoa(*port)
	server := &http.Server{
		Addr:              addr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
		WriteTimeout:      10 * time.Second,
		MaxHeaderBytes:    0, // DefaultMaxHeaderBytes
	}
	go watchHandlers(ctx, mux, server, cancel)
	listener, err := net.Listen("tcp", addr)
	if err != nil {
		cancel()
		logThis(logLine{"net listen", "fail", err.Error(), "", *serverInstanceUID, ""})
		return
	}

	logThis(logLine{"start server instance", "ok", "", *serverInstanceUID, *serverInstanceUID, ""})
	logThis(logLine{"listen for http/1.1 requests", "ok", "start server instance", addr, *serverInstanceUID, ""})
	logThis(logLine{"accept commands", "ok", "start server instance", *commandPipePath, *serverInstanceUID, ""})
	err = server.Serve(listener)
	if err != nil && err != http.ErrServerClosed {
		cancel()
		logThis(logLine{"serve resources", "fail", err.Error(), "", *serverInstanceUID, ""})
		return
	}
	// Graceful exit
	logThis(logLine{"shutdown", "ok", "graceful shutdown", "", *serverInstanceUID, ""})
}
