package main

import (
	"io"
	"net/http"
	"os"
)

// Echoes the dynamic segment the framework passes as RELAYER_ROUTE_PARAMS,
// plus a few request details so dispatch tests can see what reached Go.
func GET(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("X-Route-Pattern", os.Getenv("RELAYER_ROUTE_PATTERN"))
	w.Header().Set("X-Seen-Header", r.Header.Get("X-Probe"))
	w.Header().Set("X-Query", r.URL.RawQuery)
	w.Write([]byte(os.Getenv("RELAYER_ROUTE_PARAMS")))
}

func POST(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	w.Header().Set("Content-Type", "text/plain")
	w.WriteHeader(http.StatusCreated)
	w.Write([]byte("got:" + string(body)))
}
