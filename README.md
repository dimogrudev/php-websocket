# PHP WebSocket Server
A zero-dependency native implementation of WebSocket server in PHP.

Lightweight and minimalistic.

## Requirements
* *NIX (optional for process locking)
* PHP 7.4

Most shared hosting providers block ports for any third-party usage, so you will likely have to use a VPS or a dedicated server for running websockets.

## Quick Guide
1. Clone the repository to your machine.
2. Connect a domain and get an SSL-certificate for it (e.g. **Let's Encrypt**). Self-signed certificates are not supported.
3. Move the certificate files (**.crt** and **.key**) into a proper directory.
4. Set the path of them and other settings in the file `/config.php`.
5. Run the WebSocket server with the command `php /websocket.php &`.

It's allowed not to use an SSL-certificate in case of running the WebSocket server for local purposes or for connecting to it from a non-secure website (which is not recommended).
In other cases an SSL-certificate must be used, because modern browsers won't allow you to connect to a non-secure websocket from a secure website.
