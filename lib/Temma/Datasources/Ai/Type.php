<?php

/**
 * Type
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai;

/**
 * Types of API managed by the Ai data source.
 *
 * The type is given as a prefix of the DSN (e.g. "ai://transcript@openai/whisper-1#API_KEY").
 * Without prefix, the type is "chat".
 */
enum Type: string {
	/** Text generation (chat completion). */
	case Chat = 'chat';
	/** Audio transcription (speech-to-text). */
	case Transcript = 'transcript';
	/** Text embedding (vector representation of a text). */
	case Embedding = 'embedding';
}
