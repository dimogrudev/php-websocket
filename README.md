# PHP WebSocket Server
A zero-dependency native implementation of WebSocket server in PHP.

Lightweight and minimalistic.

## Requirements
* *NIX (optional for process locking)
* PHP 8.4

> [!IMPORTANT]
> Most shared hosting providers block ports for any third-party usage, so you will likely have to use a VPS or a dedicated server for running websockets.

## Quick Guide
Steps to run the WebSocket server:

1. Clone the repository to your machine.
2. Connect a domain and get an SSL/TLS certificate for it (e.g. **Let's Encrypt**). Self-signed certificates are not supported.
3. Move the certificate files (**.crt** and **.key**) into a proper directory.
4. Set the path of them and other settings in the file `/config.php`.
5. Run the WebSocket server with the command `php /websocket.php &`.

> [!NOTE]
> It's allowed not to use an SSL/TLS certificate in case of running the WebSocket server for local purposes or for connecting to it from a non-HTTPS website (which is not recommended).
> **In other cases an SSL/TLS certificate must be used**, because modern browsers won't allow you to connect to a non-secure websocket from a secure website.

If you want to stop the WebSocket server you can use the following commands (*NIX specific):

1. Find the PID with `lsof -i tcp:{port} | grep LISTEN`, in which `{port}` is the selected port where you run websockets.
2. Use `sudo kill -15 {pid}` to terminate the process, where `{pid}` is the PID.

## Settings
* `transport` — transport layer protocol (use **tcp** for a non-secure websocket and **tls** for a secure one).
* `host` — server host (**0.0.0.0** by default).
* `port` — server port (choose a free one from 1024 to 49151).
* `enableSsl` — enables SSL/TLS encryption.
* `sslCertPath` — SSL/TLS certificate file paths (if encryption is enabled).
