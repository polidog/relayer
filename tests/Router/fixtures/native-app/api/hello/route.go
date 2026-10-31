package main

import (
	"encoding/json"
	"net/http"
)

// A commented-out handler must not count as declared:
// func DELETE(w http.ResponseWriter, r *http.Request) {}

func GET(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"message": "Hello from Go!"})
}
