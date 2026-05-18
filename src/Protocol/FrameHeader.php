<?php

namespace WebSocket\Protocol;

use WebSocket\Registry\Opcode;

/**
 * Represents frame header DTO.
 */
readonly class FrameHeader
{
    /**
     * @param bool $isFinal Indicates if this is the final fragment.
     * @param Opcode $opcode Frame opcode.
     * @param bool $isMasked Whether the payload data is masked.
     * @param int $dataLength Length of the payload data (in bytes).
     * @param int $headerLength Length of the frame header (in bytes).
     */
    public function __construct(
        public bool $isFinal,
        public Opcode $opcode,
        public bool $isMasked,
        public int $dataLength,
        public int $headerLength,
    ) {}
}
