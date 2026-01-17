package main

import (
	"bufio"
	"context"
	"encoding/json"
	"flag"
	"io"
	"log"
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

/*
 * CONFIG
 */

var port *int
var commandPipePath *string

/*
 * PIPE UTIL
 */

// createFileForExternalProcess() creates a file in the os temporary directory that is meant to be used by the external process that's handling the HTTP request content.
func createFileForExternalProcess() (*os.File, error) {
	f, err := os.CreateTemp(os.TempDir(), "flows-http-request-file-")
	if err != nil {
		return nil, err
	}

	return f, nil
}

type AcceptedResponse struct {
	// Message    string `json:"message"`    // "Request accepted. Proceeding with process."
	// MonitorURL string `json:"monitorUrl"` // "http://example.com/tasks/123/status"
	TaskID string `json:"task_id"`
}

type commandMessage struct {
	Command           string   `json:"command"`
	Path              string   `json:"path"`
	PipeTo            string   `json:"pipe_to"`
	PipeFrom          string   `json:"pipe_from"`
	ExternalProcessID string   `json:"external_process_id"`
	AllowedMethods    []string `json:"allowed_methods"`
}

type requestMessage struct {
	Method      string              `json:"method"`
	Path        string              `json:"path"`
	Headers     map[string][]string `json:"headers"`
	Body        map[string]any      `json:"body"`
	ContentType string              `json:"contentType"`
	Files       map[string]string   `json:"files"`
	Cookies     []*http.Cookie      `json:"cookies"`
}

/*
 * FUNCTIONS
 */

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

func handlePostOrPutOrPatchMethod(pipeTo, pipeFrom *os.File, w http.ResponseWriter, r *http.Request, m *DynamicMux, entry *handlerEntry, path string, externalProcessId string) {
	// Prepare message for external process
	message := requestMessage{
		Method:  r.Method,
		Path:    r.URL.EscapedPath(),
		Headers: r.Header,
		// Body:    make(map[string]any),
		Cookies: r.Cookies(),
	}
	// Read body fields
	message.ContentType = r.Header.Get("Content-Type")
	switch message.ContentType {
	case "application/json":
		// Read raw body
		body, err := io.ReadAll(r.Body)
		if err != nil {
			w.WriteHeader(http.StatusBadRequest)
			m.mu.Lock()
			entry.handling.Store(false)
			m.mu.Unlock()
			return
		}
		// Sanitize raw body
		body = sanitize.StripInvisibleBytes(body)
		// Parse JSON body
		if !json.Valid(body) {
			w.WriteHeader(http.StatusBadRequest)
			m.mu.Lock()
			entry.handling.Store(false)
			m.mu.Unlock()
			return
		}
		// Add to message
		message.Body["raw"] = string(body)
	case "application/x-www-form-urlencoded":
		if r.Form != nil && message.Body == nil {
			for key, values := range r.Form {
				if len(values) > 0 {
					message.Body[key] = sanitize.StripInvisibleRunes(values[0])
				}
			}
		}
	case "multipart/form-data":
		if r.MultipartForm != nil && r.MultipartForm.Value != nil {
			for key, values := range r.MultipartForm.Value {
				if len(values) > 0 {
					message.Body[key] = sanitize.StripInvisibleRunes(values[0])
				}
			}
		}
		if r.MultipartForm != nil && r.MultipartForm.File != nil {
			message.Files = make(map[string]string)
			for _, fhs := range r.MultipartForm.File {
				for _, fh := range fhs {
					file, err := fh.Open()
					if err != nil {
						w.WriteHeader(http.StatusBadRequest)
						w.Write([]byte("Cannot open file: " + fh.Filename))
						m.mu.Lock()
						entry.handling.Store(false)
						m.mu.Unlock()
						return
					}

					defer file.Close()
					fileData := make([]byte, fh.Size)
					_, err = file.Read(fileData)
					if err != nil {
						w.WriteHeader(http.StatusBadRequest)
						w.Write([]byte("Cannot read file: " + fh.Filename))
						m.mu.Lock()
						entry.handling.Store(false)
						m.mu.Unlock()
						return
					}
					// Create file for external process with uploaded form file content
					fileForExternalProcess, err := createFileForExternalProcess()
					if err != nil {
						w.WriteHeader(http.StatusInternalServerError)
						w.Write([]byte("Cannot create file for external process"))
						m.mu.Lock()
						entry.handling.Store(false)
						m.mu.Unlock()
						return
					}

					_, err = writeFile(fileForExternalProcess, fileData)
					if err != nil {
						w.WriteHeader(http.StatusInternalServerError)
						w.Write([]byte("Cannot save file: " + fh.Filename))
						m.mu.Lock()
						entry.handling.Store(false)
						m.mu.Unlock()
						return
					}
					// Add to message
					message.Files[sanitize.StripInvisibleRunes(fh.Filename)] = fileForExternalProcess.Name()
				}
			}
		}
	default:
		w.WriteHeader(http.StatusBadRequest)
		m.mu.Lock()
		entry.handling.Store(false)
		m.mu.Unlock()
		return
	}
	// Prepare message for external process
	jsonData, err := json.Marshal(message)
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		w.Write([]byte("Cannot serialize message"))
		m.mu.Lock()
		entry.handling.Store(false)
		m.mu.Unlock()
		return
	}
	// Send message to external process
	err = writePipeMessage(jsonData, pipeTo)
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		w.Write([]byte("Cannot send to external process"))
		m.mu.Lock()
		entry.handling.Store(false)
		m.mu.Unlock()
		log.Println("Pipe write error:", err)
	}
	// Read external process response (valid or not?)
	response := readPipeMessage(pipeFrom)
	if response == "ok" {
		// Send accepted response
		responseBody := AcceptedResponse{
			TaskID: externalProcessId,
		}
		w.Header().Set("Content-Type", "application/json")
		jsonBody, _ := json.Marshal(responseBody)
		w.Write(jsonBody)
		w.WriteHeader(http.StatusAccepted)
		// Mark handler as disabled and handled
		m.mu.Lock()
		entry.enabled.Store(false)
		entry.handled.Store(true)
		m.mu.Unlock()
		log.Printf("HANDLER %s handled\n", path)
	} else {
		// Release handler and mark as not handled
		w.WriteHeader(http.StatusBadRequest)
		m.mu.Lock()
		entry.handling.Store(false)
		m.mu.Unlock()
	}
}

func handleGetOrDeleteMethod(pipeTo, pipeFrom *os.File, w http.ResponseWriter, r *http.Request, m *DynamicMux, entry *handlerEntry, path string) {
	message := requestMessage{
		Method:  r.Method,
		Path:    r.URL.EscapedPath(),
		Headers: r.Header,
		// Body:        nil,
		// ContentType: "",
		Cookies: r.Cookies(),
	}
	jsonData, _ := json.Marshal(message)
	err := writePipeMessage(jsonData, pipeTo)
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		w.Write([]byte("Cannot send to external process"))
		m.mu.Lock()
		entry.handling.Store(false)
		m.mu.Unlock()
		log.Println("Pipe write error:", err)
	}

	response := readPipeMessage(pipeFrom)
	if response == "nok" {
		w.WriteHeader(http.StatusBadRequest)
		m.mu.Lock()
		entry.handling.Store(false)
		m.mu.Unlock()
		return
	}
	// Send accepted response
	if len(response) > 0 {
		if false == json.Valid([]byte(response)) {
			log.Println("bad json")
		} else {
			log.Println("good json")
		}

		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(response))
	}

	w.WriteHeader(http.StatusOK)
	// Mark handler as disabled and handled
	m.mu.Lock()
	entry.enabled.Store(false)
	entry.handled.Store(true)
	m.mu.Unlock()
	log.Printf("HANDLER %s handled\n", path)
}

func writeFile(file *os.File, data []byte) (n int, err error) {
	n, err = file.Write(data)
	if err != nil {
		return 0, err
	}

	return n, nil
}

func writePipeMessage(message []byte, pipe *os.File) error {
	_, err := pipe.Write(append(message, []byte("\n")...))
	return err
}

func readPipeMessage(pipe *os.File) string {
	scanner := bufio.NewScanner(pipe)
	for {
		scanner.Scan()
		line := strings.TrimSpace(scanner.Text())
		if line == "" {
			time.Sleep(333 * time.Microsecond)
			continue
		}

		return line
	}
}

/*
 * DYNAMIC MUX
 */

type handlerEntry struct {
	enabled           atomic.Bool
	handler           http.Handler
	handling          atomic.Bool // external process is evaluating the request
	handled           atomic.Bool // external process has handled the request
	pipeTo            string      // ... to external process (write-only)
	pipeFrom          string      // ... from external process (read-only)
	externalProcessID string
	allowedMethods    []string
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

/*
 * DYNAMIC MUX METHODS
 */

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

func (m *DynamicMux) Register(path string, pipeToProcess string, pipeFromProcess string, externalProcessID string, allowedMethods []string) {
	if slices.Contains(allowedMethods, "CONNECT") || slices.Contains(allowedMethods, "HEAD") || slices.Contains(allowedMethods, "OPTIONS") || slices.Contains(allowedMethods, "TRACE") {
		log.Printf("Cannot register handler %s: invalid allowed methods\n", path)
		return
	}
	if len(allowedMethods) == 0 {
		allowedMethods = []string{"GET", "POST", "PUT", "PATCH", "DELETE"}
	}

	entry := &handlerEntry{}
	entry.enabled.Store(true)
	entry.handling.Store(false)
	entry.handled.Store(false)
	entry.pipeTo = pipeToProcess
	entry.pipeFrom = pipeFromProcess
	entry.externalProcessID = externalProcessID
	entry.allowedMethods = allowedMethods

	// Define handler function
	entry.handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Fail if handled previously or about to deregister
		if !entry.enabled.Load() {
			http.NotFound(w, r)
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
		// Fail if already handling
		if entry.handling.Load() {
			w.WriteHeader(http.StatusLocked)
			return
		}
		// Mark as handling, prevent concurrent handling (multiple requests)
		m.mu.Lock()
		entry.handling.Store(true)
		m.mu.Unlock()
		// Parse form data
		if r.Method == http.MethodPatch || r.Method == http.MethodPost || r.Method == http.MethodPut {
			contentType := r.Header.Get("Content-Type")
			if strings.HasPrefix(contentType, "multipart/form-data") {
				err := r.ParseMultipartForm(4 << 20) // 4 MB
				if err != nil {
					w.WriteHeader(http.StatusBadRequest)
					m.mu.Lock()
					entry.handling.Store(false)
					m.mu.Unlock()
					return
				}
			} else {
				// Content-Type: application/x-www-form-urlencoded
				err := r.ParseForm()
				if err != nil {
					w.WriteHeader(http.StatusBadRequest)
					m.mu.Lock()
					entry.handling.Store(false)
					m.mu.Unlock()
					return
				}
			}
		}
		// Start response process
		//
		// Open pipes to communicate with external process
		pipeTo, err := os.OpenFile(entry.pipeTo, os.O_WRONLY, 0664)
		if err != nil {
			w.WriteHeader(http.StatusInternalServerError)
			log.Printf("Pipe %s error: %s\n", entry.pipeTo, err)
			return
		}
		defer pipeTo.Close()

		pipeFrom, err := os.OpenFile(entry.pipeFrom, os.O_RDONLY, 0664)
		if err != nil {
			w.WriteHeader(http.StatusInternalServerError)
			log.Printf("Pipe %s error: %s\n", entry.pipeFrom, err)
			return
		}
		defer pipeFrom.Close()
		// Send message to external process for request handling
		switch r.Method {
		case http.MethodGet, http.MethodDelete:
			handleGetOrDeleteMethod(pipeTo, pipeFrom, w, r, m, entry, path)
		case http.MethodPatch, http.MethodPost, http.MethodPut:
			handlePostOrPutOrPatchMethod(pipeTo, pipeFrom, w, r, m, entry, path, externalProcessID)
		}
	})

	m.mu.Lock()
	m.handlers[path] = entry
	m.mu.Unlock()

	log.Printf("Registered handler: %s\n", path)
}

func (m *DynamicMux) Deregister(path string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if entry, ok := m.handlers[path]; ok {
		entry.enabled.Store(false)
		delete(m.handlers, path)
		log.Printf("Deregistered handler: %s\n", path)
	}
}

/*
 * HANDLER WATCHER
 */

func watchHandlers(ctx context.Context, mux *DynamicMux, server *http.Server, cancel context.CancelFunc) {
	// Allow time to register handlers
	time.Sleep(3 * time.Second)
	// Start checking handlers
	for {
		select {
		case <-ctx.Done():
			log.Println("Context done")
			return
		default:
			c := len(mux.handlers)
			for k, v := range mux.handlers {
				if !v.enabled.Load() && v.handled.Load() {
					c--
					// House-keeping
					os.Remove(v.pipeTo)
					os.Remove(v.pipeFrom)
					log.Printf("Removed pipes: %s %s\n", v.pipeTo, v.pipeFrom)
					delete(mux.handlers, k)
					log.Printf("Deleted handler: %s\n", k)
				}
			}
			if c == 0 {
				// Remove command pipe
				if _, err := os.Stat(*commandPipePath); err == nil {
					os.Remove(*commandPipePath)
					log.Printf("Removed command pipe: %s\n", *commandPipePath)
				}

				log.Println("No handlers available")
				cancel()
				time.Sleep(time.Second)
				log.Println("Shutting down server")
				server.Shutdown(ctx)
			}
		}
		// Take it easy on the CPU
		time.Sleep(33 * time.Millisecond)
	}
}

/*
 * PIPE WATCHER
 */

func watchPipe(ctx context.Context, mux *DynamicMux, pipePath *string) {
	// Allow time to register handlers
	time.Sleep(time.Second)
	f, err := os.OpenFile(*pipePath, os.O_RDONLY, 0664)
	if err != nil {
		log.Println("Pipe open error: ", err)
		return
	}

	defer f.Close()
	scanner := bufio.NewScanner(f)
	for {
		select {
		case <-ctx.Done():
			log.Println("Context done")
			return
		default:
			if !scanner.Scan() {
				log.Println("Command pipe closed")
				return
			}

			line := scanner.Text()
			if line == "" {
				continue
			}
			if false == json.Valid([]byte(line)) {
				log.Printf("Invalid JSON in pipe: %s\n", line)
				continue
			}

			var message commandMessage
			err = json.Unmarshal([]byte(line), &message)
			if err != nil {
				log.Printf("Invalid command message in pipe: %s\n", line)
				continue
			}

			switch message.Command {
			case "REGISTER":
				mux.Register(message.Path, message.PipeTo, message.PipeFrom, message.ExternalProcessID, message.AllowedMethods)
			case "DEREGISTER":
				mux.Deregister(message.Path)
			default:
				log.Printf("Invalid command: %s\n", line)
				return
			}
		}
		// Take it easy on the CPU
		time.Sleep(33 * time.Millisecond)
	}
}

/*
MAIN
*/

func main() {
	port = flag.Int("port", 8888, "The port the server listen for HTTP requests")
	commandPipePath = flag.String("command-pipe-path", "/tmp/flows-http-server", "The pipe where the server receives commands")
	help := flag.Bool("help", false, "Show this help")
	flag.Parse()
	if *help {
		flag.Usage()
		return
	}

	log.SetFlags(log.LstdFlags | log.Lshortfile)
	mux := NewDynamicMux()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go watchPipe(ctx, mux, commandPipePath)
	addr := ":" + strconv.Itoa(*port)
	server := &http.Server{
		Addr:    addr,
		Handler: mux,
	}
	go watchHandlers(ctx, mux, server, cancel)
	listener, err := net.Listen("tcp", addr)
	if err != nil {
		cancel()
		log.Fatal(err)
		return
	}

	log.Printf("Listening on %s (HTTP/1.1)\n", addr)
	log.Printf("Named pipe: %s\n", *commandPipePath)
	err = server.Serve(listener)
	if err != nil {
		log.Fatal(err)
	}
}
