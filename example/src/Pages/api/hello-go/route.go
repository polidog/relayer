// A native route handler: the Go counterpart of route.php.
//
// Declare `func GET` / `POST` / … exactly like a Next.js Go Route Handler —
// no main(), no framework import. `vendor/bin/relayer native:build`
// generates the entrypoint and compiles this file to
// var/cache/native/bin/api__hello-go; the router then starts that binary as
// a CGI process per request (see "Native route handlers (Go)" in the
// README). Nothing is autowired: the request arrives as CGI variables,
// dynamic segments as the RELAYER_ROUTE_PARAMS JSON env var.
package main

import (
	"encoding/json"
	"net/http"
)

func GET(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"message": "Hello from Go!"})
}
