package main

// A simplified web server using Unix Domain Sockets.
//
// Features:
//  - Command socket
//  - One persistent unix socket connection per handler
//  - Single-shot handlers
//
// External process protocol for commands (JSON message):
//  - REGISTER command message (type Command)
//  - DEREGISTER command message (type Command)
//
// When HTTP request arrives, server sends JSON message (type RequestMsg) on handler socket
// External process replies with JSON message (type ResponseMsg)

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"flag"
	"io"
	"log"
	"mime"
	"net"
	"net/http"
	"os"
	"os/signal"
	"slices"
	"strings"
	"sync"
	"syscall"
	"time"

	sanitize "flows.local/http-server/sanitize"
)

// ---------------- Messages ----------------

type PingPong struct {
	Message   string `json:"message"`
	Status    string `json:"status"`
	Now       string `json:"now"`
	ServerUid string `json:"server_uid"`
}

type Command struct {
	Command           string   `json:"command"`
	Path              string   `json:"path"`
	SocketFile        string   `json:"socket_file"`
	ExternalProcessID string   `json:"external_process_id"`
	AllowedMethods    []string `json:"allowed_methods"`
	Timeout           int      `json:"timeout"`
}

type RequestMsg struct {
	Method      string              `json:"method"`
	Path        string              `json:"path"`
	Headers     map[string][]string `json:"headers"`
	Body        any                 `json:"body,omitempty"`
	ContentType string              `json:"content_type"`
	Files       map[string]string   `json:"files,omitempty"`
	Cookies     []*http.Cookie      `json:"cookies"`
	InstanceUID string              `json:"instance_uid"` // Server instance unique identifier
}

type ResponseMsg struct {
	Ok          bool   `json:"ok"`           // External process signal, FALSE => respond with 400, TRUE => 200
	Code        int    `json:"code"`         // Reserved for custom code
	Status      string `json:"status"`       // Reserved for custom status message
	Message     string `json:"message"`      // Reserved for custom message
	InstanceUID string `json:"instance_uid"` // External process unique identifier
}

// ---------------- Handler Entry ----------------

type HandlerEntry struct {
	// Conn              net.Conn
	Enabled           bool
	Handling          bool         // external process is evaluating the request
	Handled           bool         // external process has handled the request
	SocketFile        string       // Socket to <-> from external PHP process
	ExternalProcessID string       // PHP process unique identifier
	AllowedMethods    []string     // Resource allowed HTTP methods
	Timeout           int          // The resource is not valid (stale) if timeout <= 0
	Handler           http.Handler // Request handler
	mu                sync.Mutex
}

// ---------------- Logging ----------------

type LogLine struct {
	Operation         string `json:"operation"`
	Status            string `json:"status"`
	Reason            string `json:"reason,omitempty"`
	Resource          string `json:"resource,omitempty"`
	ServerInstance    string `json:"server_instance"`
	ExternalProcessID string `json:"external_process_id,omitempty"`
}

func logThis(line LogLine) {
	j, _ := json.Marshal(line)
	log.Println(string(j))
}

// ---------------- Dynamic Mux ----------------

type DynamicMux struct {
	mu       sync.RWMutex
	handlers map[string]*HandlerEntry
}

func NewMux() *DynamicMux {
	return &DynamicMux{handlers: make(map[string]*HandlerEntry)}
}

// ---------------- Handle requests ----------------

/* Creates a file in the os temporary directory that is meant to be used by the external process that's
 * handling the HTTP request content. */
func createFileForExtProc() (*os.File, error) {
	f, err := os.CreateTemp(os.TempDir(), "flows-http-request-file-")
	if err != nil {
		return nil, err
	}

	return f, nil
}

/* Clean up in case of errors before sending message to external process.
 * Delete files in the os temporary directory that is meant to be used by the external process that's
 * handling the HTTP request content. */
func deleteFilesForExtProc(files map[string]string, e *HandlerEntry) {
	for _, filePath := range files {
		_, err := os.Stat(filePath)
		if err != nil {
			// File previously removed ?!
			logThis(LogLine{"stat", "fail", err.Error(), filePath, serverUID, e.ExternalProcessID})
			continue
		}

		err = os.Remove(filePath)
		if err != nil {
			// File previously removed ?!
			logThis(LogLine{"remove:file", "fail", err.Error(), filePath, serverUID, e.ExternalProcessID})
			continue
		}
	}
}

func handleWithoutBody(req *RequestMsg, e *HandlerEntry) (ResponseMsg, error) {
	req.Body = nil
	req.Files = nil
	return ResponseMsg{
		Ok:          true,
		Code:        0,
		Status:      "success",
		Message:     "Set message body",
		InstanceUID: e.ExternalProcessID}, nil
}

func handleWithBody(r *http.Request, req *RequestMsg, e *HandlerEntry) (ResponseMsg, error) {
	rmSize := ResponseMsg{
		Ok:          false,
		Code:        400,
		Status:      "fail",
		Message:     "Request max size is 16 MB",
		InstanceUID: e.ExternalProcessID}

	switch req.ContentType {
	case "application/json":
		// Read raw body
		body, err := io.ReadAll(r.Body)
		if err != nil {
			return rmSize, errors.New(rmSize.Message)
		}
		// Sanitize raw body
		body = sanitize.StripInvisibleBytes(body)
		// Parse JSON body
		if !json.Valid(body) {
			rm := ResponseMsg{
				Ok:          false,
				Code:        400,
				Status:      "fail",
				Message:     "Invalid JSON",
				InstanceUID: e.ExternalProcessID}
			return rm, errors.New(rm.Message)
		}
		// Set message body
		req.Body = string(body)
		return ResponseMsg{
			Ok:          true,
			Code:        0,
			Status:      "success",
			Message:     "Set message body",
			InstanceUID: e.ExternalProcessID}, nil

	case "application/x-www-form-urlencoded":
		err := r.ParseForm()
		if err != nil {
			return rmSize, errors.New(rmSize.Message)
		}
		if r.Form != nil && req.Body == nil {
			/* Only concrete types (e.g., map[string]string) support indexing.
			 * Fix by doing a type assertion (or switching on the type) and ensuring message.Body
			 * is a map before writing into it. */
			mb := make(map[string]string)
			for key, values := range r.Form {
				if len(values) > 0 {
					mb[key] = sanitize.StripInvisibleRunes(values[0])
				}
			}
			if len(mb) > 0 {
				req.Body = mb
			} else {
				req.Body = []any{}
			}
		}

		return ResponseMsg{
			Ok:          true,
			Code:        0,
			Status:      "success",
			Message:     "Set message body",
			InstanceUID: e.ExternalProcessID}, nil

	case "multipart/form-data":
		err := r.ParseMultipartForm(maxBodySize)
		if err != nil {
			return rmSize, errors.New(rmSize.Message)
		}
		if r.MultipartForm != nil && r.MultipartForm.Value != nil {
			mb := make(map[string]string)
			for key, values := range r.MultipartForm.Value {
				if len(values) > 0 {
					mb[key] = sanitize.StripInvisibleRunes(values[0])
				}
			}
			if len(mb) > 0 {
				req.Body = mb
			} else {
				req.Body = []any{}
			}
		}
		if r.MultipartForm != nil && r.MultipartForm.File != nil {
			req.Files = make(map[string]string)
			for _, fhs := range r.MultipartForm.File {
				for _, fh := range fhs {
					file, err := fh.Open()
					if err != nil {
						rm := ResponseMsg{
							Ok:          false,
							Code:        400,
							Status:      "fail",
							Message:     "Failed to open file " + fh.Filename,
							InstanceUID: e.ExternalProcessID}
						return rm, errors.New(rm.Message)
					}
					// Create file for external process with uploaded form file content
					fileForExtProc, err := createFileForExtProc()
					if err != nil {
						file.Close()
						// Delete previously created files
						defer deleteFilesForExtProc(req.Files, e)

						rm := ResponseMsg{
							Ok:          false,
							Code:        400,
							Status:      "fail",
							Message:     "Failed to create file for external process",
							InstanceUID: e.ExternalProcessID}
						return rm, errors.New(rm.Message)
					}
					// Copy file for external process use
					_, err = io.Copy(fileForExtProc, file)
					fileForExtProc.Close()
					file.Close()
					if err != nil {
						// Delete previously created files
						req.Files[sanitize.StripInvisibleRunes(fh.Filename)] = fileForExtProc.Name()
						defer deleteFilesForExtProc(req.Files, e)

						rm := ResponseMsg{
							Ok:          false,
							Code:        400,
							Status:      "fail",
							Message:     "Failed to save file " + fh.Filename,
							InstanceUID: e.ExternalProcessID}
						return rm, errors.New(rm.Message)
					}
					// Add to message
					req.Files[sanitize.StripInvisibleRunes(fh.Filename)] = fileForExtProc.Name()
				}
			}
			// Remove temporary files
			r.MultipartForm.RemoveAll()
		}
	}
	return ResponseMsg{
		Ok:          true,
		Code:        0,
		Status:      "success",
		Message:     "Set message body and/or files",
		InstanceUID: e.ExternalProcessID}, nil
}

func (m *DynamicMux) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	m.mu.RLock()
	e := m.handlers[r.URL.Path]
	m.mu.RUnlock()

	if e == nil {
		http.NotFound(w, r)
		return
	} else if (r.URL.Path == "/ping") && (r.Method == http.MethodGet) {
		e.Handler.ServeHTTP(w, r)
		return
	}

	e.mu.Lock()
	if !e.Enabled {
		http.NotFound(w, r)
		e.mu.Unlock()
		return
	} else if e.Handling {
		w.WriteHeader(http.StatusLocked)
		e.mu.Unlock()
		return
	} else if !slices.Contains(e.AllowedMethods, r.Method) {
		w.WriteHeader(http.StatusMethodNotAllowed)
		e.mu.Unlock()
		return
	} else if hAccept := r.Header.Get("Accept"); hAccept != "" {
		canServe := false
		if hAccept == "*/*" {
			canServe = true
		} else {
			for _, v := range strings.Split(hAccept, ",") {
				mt, _, err := mime.ParseMediaType(strings.TrimSpace(v))
				if err != nil {
					w.WriteHeader(http.StatusBadRequest)
					e.mu.Unlock()
					return
				}
				if mt == "application/json" || mt == "application/x-www-form-urlencoded" || mt == "multipart/form-data" {
					canServe = true
					break
				}
			}
		}
		if !canServe {
			w.Header().Set("Accept", "application/json, application/x-www-form-urlencoded, multipart/form-data")
			w.WriteHeader(http.StatusUnsupportedMediaType)
			e.mu.Unlock()
			return
		}
	}

	e.Handling = true
	e.mu.Unlock()

	req := RequestMsg{
		Method:      r.Method,
		Path:        r.URL.Path,
		Headers:     r.Header,
		Cookies:     r.Cookies(),
		InstanceUID: serverUID,
	}

	var handleResp ResponseMsg
	var err error
	if slices.Contains([]string{http.MethodDelete, http.MethodGet}, r.Method) {
		handleResp, err = handleWithoutBody(&req, e)
	} else {
		r.Body = http.MaxBytesReader(w, r.Body, maxBodySize)
		var ct string
		ct, _, err = mime.ParseMediaType(r.Header.Get("Content-Type"))
		if err != nil {
			w.WriteHeader(http.StatusBadRequest)
			e.mu.Lock()
			e.Handling = false
			e.mu.Unlock()
			return
		}

		req.ContentType = ct
		handleResp, err = handleWithBody(r, &req, e)
	}
	if err != nil {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusBadRequest)
		jm, _ := json.Marshal(handleResp)
		w.Write(jm)
		e.mu.Lock()
		e.Handling = false
		e.mu.Unlock()
		return
	}

	conn, err := net.Dial("unix", e.SocketFile)
	if err != nil {
		w.WriteHeader(http.StatusBadRequest)
		e.mu.Lock()
		e.Handling = false
		e.mu.Unlock()
		return
	}

	duration := time.Duration(timeoutReadExtProc) * time.Second
	conn.SetReadDeadline(time.Now().Add(duration))
	defer conn.Close()

	enc := json.NewEncoder(conn)
	dec := json.NewDecoder(conn)

	if err := enc.Encode(req); err != nil {
		defer deleteFilesForExtProc(req.Files, e)
		logThis(LogLine{"socket:write", "fail", err.Error(), e.SocketFile, serverUID, e.ExternalProcessID})
		w.WriteHeader(http.StatusInternalServerError)
		e.mu.Lock()
		e.Handling = false
		e.mu.Unlock()
		return
	}

	var resp ResponseMsg
	if err := dec.Decode(&resp); err != nil && err != io.EOF {
		/* Don't call deleteFilesForExtProc() here because the
		 * external process might still be using these files */
		logThis(LogLine{"socket:read", "fail", err.Error(), e.SocketFile, serverUID, e.ExternalProcessID})
		w.WriteHeader(http.StatusInternalServerError)
		e.mu.Lock()
		e.Handling = false
		e.mu.Unlock()
		return
	}

	e.mu.Lock()
	e.Handling = false
	w.Header().Set("Content-Type", "application/json")
	if resp.Ok {
		e.Enabled = false // single shot
		e.Handled = true  // ready for removal
		w.WriteHeader(http.StatusAccepted)
	} else {
		w.WriteHeader(http.StatusBadRequest)
	}

	jm, _ := json.Marshal(resp)
	w.Write(jm)
	e.mu.Unlock()
}

// ---------------- Command Socket ----------------

type CommandReply struct {
	Ok    bool   `json:"ok"`
	Error string `json:"error,omitempty"`
}

func register(cmd Command, mux *DynamicMux) CommandReply {
	if slices.Contains(cmd.AllowedMethods, http.MethodConnect) || slices.Contains(cmd.AllowedMethods, http.MethodHead) || slices.Contains(cmd.AllowedMethods, http.MethodOptions) || slices.Contains(cmd.AllowedMethods, http.MethodTrace) {
		return CommandReply{Ok: false, Error: "invalid method"}
	}

	cmd.Path = strings.TrimSpace(cmd.Path)
	cmd.Path = sanitize.StripInvisibleRunes(cmd.Path)

	mux.mu.Lock()
	if _, exists := mux.handlers[cmd.Path]; exists {
		mux.mu.Unlock()
		return CommandReply{Ok: false, Error: "path already registered"}
	}
	// Preemptive key reservation
	e := &HandlerEntry{
		Enabled:  true,
		Handling: true}
	// Register before unlocking to prevent double registration in the meantime
	mux.handlers[cmd.Path] = e
	mux.mu.Unlock()

	e.mu.Lock()
	e.Enabled = true
	e.Handling = false // Set the entry ready for service
	e.Handled = false
	e.SocketFile = cmd.SocketFile
	e.ExternalProcessID = cmd.ExternalProcessID
	e.Timeout = cmd.Timeout

	if len(cmd.AllowedMethods) == 0 {
		e.AllowedMethods = []string{http.MethodGet, http.MethodPost, http.MethodPut, http.MethodPatch, http.MethodDelete}
	} else {
		e.AllowedMethods = cmd.AllowedMethods
	}

	e.mu.Unlock()
	return CommandReply{Ok: true}
}

func deregister(cmd Command, mux *DynamicMux) CommandReply {
	mux.mu.Lock()
	defer mux.mu.Unlock()

	e := mux.handlers[cmd.Path]
	if e == nil {
		return CommandReply{Ok: false, Error: "resource not found"}
	} else if e.ExternalProcessID != cmd.ExternalProcessID {
		return CommandReply{Ok: false, Error: "wrong resource owner"}
	}

	delete(mux.handlers, cmd.Path)
	return CommandReply{Ok: true}
}

/* Register /ping resource so external processes/clients can check if server
 * is running. This resource always exists */
func registerPing(mux *DynamicMux) {
	path := "/ping"
	e := &HandlerEntry{
		Enabled:           true,
		Handling:          false,
		Handled:           false,
		SocketFile:        "",
		ExternalProcessID: "",
		AllowedMethods:    []string{http.MethodGet},
		Timeout:           -1, // Run forever
		Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			pingPong := PingPong{
				Message:   "pong",
				Status:    status,
				Now:       time.Now().Format(time.DateTime),
				ServerUid: serverUID,
			}
			jm, _ := json.Marshal(pingPong)
			w.Header().Set("Content-Type", "application/json")
			w.Write(jm)
		}),
	}

	mux.mu.Lock()
	mux.handlers[path] = e
	mux.mu.Unlock()
	logThis(LogLine{"register", "ok", "", path, serverUID, ""})
}

func listenForClient(listener net.Listener, ctx context.Context, mux *DynamicMux) {
	defer listener.Close()
	ticker := time.NewTicker(time.Millisecond)
	for {
		select {
		case <-ctx.Done():
			ticker.Stop()
			logThis(LogLine{"conn:accept", "ok", "context done", cmdSockPath, serverUID, ""})
			return

		case <-ticker.C:
			conn, err := listener.Accept()
			if err != nil {
				logThis(LogLine{"conn:accept", "fail", err.Error(), cmdSockPath, serverUID, ""})
				continue
			}

			handleClient(conn, mux)
		}
	}
}

func handleClient(conn net.Conn, mux *DynamicMux) {
	defer conn.Close()

	dec := json.NewDecoder(bufio.NewReader(conn))
	enc := json.NewEncoder(conn)

	var cmd Command
	err := dec.Decode(&cmd)
	if err != nil {
		var reason string
		if err == io.EOF {
			reason = "empty string"
			return
		} else {
			reason = err.Error()
		}

		enc.Encode(CommandReply{Ok: false, Error: reason})
		return
	}

	var resp CommandReply
	switch cmd.Command {
	case "REGISTER":
		resp = register(cmd, mux)
	case "DEREGISTER":
		resp = deregister(cmd, mux)
	default:
		resp = CommandReply{Ok: false, Error: "unknown command"}
	}

	if err := enc.Encode(resp); err != nil {
		logThis(LogLine{"client:request:reply", "fail", err.Error(), cmdSockPath, serverUID, cmd.ExternalProcessID})
	} else {
		logThis(LogLine{"register", "ok", "", cmd.Path, serverUID, cmd.ExternalProcessID})
	}
}

func decreaseResourceLifetime(ctx context.Context, mux *DynamicMux) {
	ticker := time.NewTicker(time.Second)
	for {
		select {
		case <-ctx.Done():
			ticker.Stop()
			logThis(LogLine{"ticker:stop", "ok", "context done", "", serverUID, ""})
			return

		case <-ticker.C:
			for k, v := range mux.handlers {
				v.mu.Lock()
				if v.Handled || v.Handling || v.Timeout <= 0 || k == "/ping" { // /ping runs forever
					v.mu.Unlock()
					continue
				}

				v.Timeout--
				v.mu.Unlock()
			}
		}
	}
}

// ---------------- Housekeeping ----------------

func housekeeping(parent context.Context, cancel context.CancelFunc, mux *DynamicMux) {
	logThis(LogLine{"housekeep", "start", "", "", serverUID, ""})
	ticker := time.NewTicker(3 * time.Second)
	for {
		select {
		case <-parent.Done():
			os.Remove(cmdSockPath) // Remove command socket file
			logThis(LogLine{"shutdown", "ok", "context done", "func housekeeping()", serverUID, ""})
			return

		case <-ticker.C:
			mux.mu.Lock()
			for k, v := range mux.handlers {
				if k == "/ping" || v.Handling {
					continue
				}

				if (!v.Enabled && v.Handled) || v.Timeout <= 0 {
					reason := ""
					if !v.Enabled && v.Handled {
						reason = "handled"
					} else {
						reason = "timeout"
					}

					_, err := os.Stat(v.SocketFile)
					if err == nil {
						os.Remove(v.SocketFile)
						logThis(LogLine{"remove:file", "ok", reason, v.SocketFile, serverUID, v.ExternalProcessID})
					}

					tempUID := v.ExternalProcessID
					delete(mux.handlers, k)
					logThis(LogLine{"remove:resource", "ok", reason, k, serverUID, tempUID})
				}
			}
			mux.mu.Unlock()
			remaining := len(mux.handlers) - 1 // -1 is /ping (this resource always exists)
			if remaining == 0 {
				ticker.Stop()
				logThis(LogLine{"shutdown", "ok", "no resources available", "", serverUID, ""})
				status = "shutdown"
				cancel()
			}
		}
	}
}

// ---------------- Main ----------------

var httpAddr string
var cmdSockPath string
var serverUID string
var timeoutReadExtProc int
var help bool
var maxBodySize int64
var status string

func main() {
	maxBodySize = int64(16 << 20) // 16 MB;
	tempDir := os.TempDir()

	flag.StringVar(&httpAddr, "address", "0.0.0.0:9090", "Server listens on this address for HTTP requests")
	flag.StringVar(&cmdSockPath, "command-socket", tempDir+"/server.cmd.sock", "Socket file external processes must use to register resources")
	flag.StringVar(&serverUID, "server-uid", "", "Server instance unique identifier (no default, mandatory)")
	flag.IntVar(&timeoutReadExtProc, "timeout-read-external-process", 30, "How long (in seconds) to wait for external process write")

	flag.BoolVar(&help, "help", false, "Show this help")
	flag.Parse()
	if help || serverUID == "" {
		flag.Usage()
		return
	}

	status = "starting"
	if _, err := os.Stat(cmdSockPath); err == nil {
		os.Remove(cmdSockPath)
	}
	// Run server for 1 year
	ctx, cancel := context.WithCancel(context.Background())
	// The server and handler(s) entry(ies)
	mux := NewMux()
	httpServer := &http.Server{
		Addr:    httpAddr,
		Handler: mux,
	}
	// Listen for commands
	listener, err := net.Listen("unix", cmdSockPath)
	if err != nil {
		panic(err.Error())
	} else {
		logThis(LogLine{"net:listen", "ok", "", cmdSockPath, serverUID, ""})
	}

	go func() {
		listenForClient(listener, ctx, mux)
	}()
	// Register ping / heartbeat
	registerPing(mux)
	// Start server
	go func() {
		logThis(LogLine{"http:listen", "ok", "", httpServer.Addr, serverUID, ""})
		status = "listening"
		err := httpServer.ListenAndServe()
		if err == http.ErrServerClosed {
			logThis(LogLine{"http:shutdown", "ok", "shutdown", httpServer.Addr, serverUID, ""})
		} else {
			logThis(LogLine{"http:shutdown", "fail", err.Error(), httpServer.Addr, serverUID, ""})
		}
	}()

	go func() {
		<-ctx.Done()
		newCtx, newCancel := context.WithTimeout(context.Background(), time.Duration(timeoutReadExtProc)*time.Second)
		httpServer.Shutdown(newCtx)
		newCancel()
	}()

	go func() {
		decreaseResourceLifetime(ctx, mux)
	}()

	go func() {
		housekeeping(ctx, cancel, mux)
	}()

	sig := make(chan os.Signal, 1)
	signal.Notify(sig, os.Interrupt, syscall.SIGTERM)
	select {
	case <-sig:
		logThis(LogLine{"context:cancel", "ok", "os interrupt", "", serverUID, ""})
		status = "shutdown"
		cancel()

	case <-ctx.Done():
		return
	}
}
