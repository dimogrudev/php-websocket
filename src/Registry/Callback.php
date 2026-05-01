<?php

namespace WebSocket\Registry;

/**
 * Represents callback registry
 */
enum Callback: int
{
    case SERVER_START       = 0;
    case SERVER_STOP        = 1;
    case CLIENT_CONNECT     = 2;
    case CLIENT_DISCONNECT  = 3;
    case MESSAGE_RECEIVE    = 4;
    case HANDSHAKE          = 5;
}
