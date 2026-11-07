<?php

/**
 * OpenAiCompatible
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai\Providers;

use \Temma\Datasources\Ai\Type as TµAiType;

/**
 * Generic OpenAI-compatible provider for the Ai data source.
 *
 * This class is not intended to be instantiated directly.
 * It is used internally by \Temma\Datasources\Ai.
 *
 * This provider works with any service that implements the OpenAI API
 * (Groq, Together AI, Fireworks AI, DeepInfra, LiteLLM, etc.). Managed types:
 * <ul>
 *   <li>chat: Chat Completions API. Supports: text, images, audio input. JSON structured output.</li>
 *   <li>transcript: Audio Transcriptions API. The first attachment is the audio file,
 *       the prompt is an optional context hint. Returns the transcribed text.</li>
 *   <li>embedding: Embeddings API. Returns the vector of the prompt (list of floats).</li>
 * </ul>
 * The endpoint URL given in the DSN must match the type
 * (e.g. <tt>ai://transcript@[https://api.groq.com/openai/v1/audio/transcriptions]/whisper-large-v3#gsk-XXX</tt>).
 *
 * Common service chat endpoint URLs:
 * <ul>
 *   <li>Groq: <tt>https://api.groq.com/openai/v1/chat/completions</tt></li>
 *   <li>Together AI: <tt>https://api.together.xyz/v1/chat/completions</tt></li>
 *   <li>Fireworks AI: <tt>https://api.fireworks.ai/inference/v1/chat/completions</tt></li>
 *   <li>DeepInfra: <tt>https://api.deepinfra.com/v1/openai/chat/completions</tt></li>
 * </ul>
 *
 * Child classes define the endpoint URL of each type they support, in the API_URLS constant.
 * Actual feature support depends on the underlying service and model.
 */
class OpenAiCompatible implements \Temma\Datasources\Ai\Provider {
	/** Provider name, used in error messages. */
	const string NAME = 'OpenAI-compatible';
	/** Endpoint URL of each supported type (empty: the URL is given in the DSN). */
	const array API_URLS = [];
	/** API key. */
	protected string $_apiKey;
	/** API endpoint URL. */
	protected string $_apiUrl;
	/** Type of API. */
	protected TµAiType $_type;

	/**
	 * Constructor.
	 * @param	string		$apiKey		(optional) API key.
	 * @param	string		$endpointUrl	(optional) Endpoint URL. Defaults to the URL of the type in API_URLS.
	 * @param	TµAiType	$type		(optional) Type of API. Defaults to chat.
	 * @throws	\Temma\Exceptions\Database	If the type is not supported.
	 */
	public function __construct(string $apiKey='', string $endpointUrl='', TµAiType $type=TµAiType::Chat) {
		$this->_apiKey = $apiKey;
		$this->_apiUrl = $endpointUrl ?: (static::API_URLS[$type->value] ?? '');
		$this->_type = $type;
		if (!$this->_apiUrl)
			throw new \Temma\Exceptions\Database(static::NAME . " provider doesn't support the '{$type->value}' type.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Build the API request payload.
	 * @param	string	$model		Model identifier.
	 * @param	string	$prompt		User prompt.
	 * @param	array	$options	Normalized options.
	 * @return	array	Associative array with 'url', 'headers', 'body' and (for transcriptions) 'encoding' keys.
	 * @throws	\Temma\Exceptions\Database	If no audio file is given for a transcription.
	 */
	public function buildPayload(string $model, string $prompt, array $options) : array {
		$request = [
			'url'     => $this->_apiUrl,
			'headers' => $this->_apiKey ? ['Authorization: Bearer ' . $this->_apiKey] : [],
			'body'    => match ($this->_type) {
				TµAiType::Chat       => $this->_buildChatBody($model, $prompt, $options),
				TµAiType::Transcript => $this->_buildTranscriptBody($model, $prompt, $options),
				TµAiType::Embedding  => ['model' => $model, 'input' => $prompt],
			},
		];
		if ($this->_type === TµAiType::Transcript)
			$request['encoding'] = 'multipart';
		return ($request);
	}
	/**
	 * Parse the API response.
	 * @param	array	$data	Decoded JSON response.
	 * @return	mixed	The response content (string for chat and transcript, list of floats for embedding).
	 * @throws	\Temma\Exceptions\Database	If the API returned an error.
	 */
	public function parseResponse(array $data) : mixed {
		if (isset($data['error'])) {
			$msg = $data['error']['message'] ?? 'Unknown error.';
			throw new \Temma\Exceptions\Database(static::NAME . " API error: $msg", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		return (match ($this->_type) {
			TµAiType::Chat       => $data['choices'][0]['message']['content'] ?? null,
			TµAiType::Transcript => $data['text'] ?? null,
			TµAiType::Embedding  => $data['data'][0]['embedding'] ?? null,
		});
	}

	/* ********** PROTECTED METHODS ********** */
	/**
	 * Build the request body of a chat completion.
	 * @param	string	$model		Model identifier.
	 * @param	string	$prompt		User prompt.
	 * @param	array	$options	Normalized options.
	 * @return	array	The request body.
	 */
	protected function _buildChatBody(string $model, string $prompt, array $options) : array {
		// build messages list
		$messages = [];
		if (isset($options['system']))
			$messages[] = ['role' => 'system', 'content' => $options['system']];
		// conversation history
		if (isset($options['messages'])) {
			foreach ($options['messages'] as $msg) {
				if (isset($msg['user'])) {
					$content = $this->_buildContent($msg['user'], $msg['attachments'] ?? null);
					$messages[] = ['role' => 'user', 'content' => $content];
				} else if (isset($msg['ai']))
					$messages[] = ['role' => 'assistant', 'content' => $msg['ai']];
			}
		}
		// current prompt with attachments
		$content = $this->_buildContent($prompt, $options['attachments'] ?? null);
		$messages[] = ['role' => 'user', 'content' => $content];
		// build body
		$body = [
			'model'    => $model,
			'messages' => $messages,
		];
		if (isset($options['temperature']))
			$body['temperature'] = (float)$options['temperature'];
		if (isset($options['max_tokens']))
			$body['max_tokens'] = (int)$options['max_tokens'];
		return ($body);
	}
	/**
	 * Build the request body of an audio transcription (sent as multipart/form-data).
	 * @param	string	$model		Model identifier.
	 * @param	string	$prompt		User prompt, used as a context hint (ignored if empty).
	 * @param	array	$options	Normalized options. The first attachment is the audio file.
	 * @return	array	The request body.
	 * @throws	\Temma\Exceptions\Database	If no audio file is given.
	 */
	protected function _buildTranscriptBody(string $model, string $prompt, array $options) : array {
		$att = $options['attachments'][0] ?? null;
		if (!$att)
			throw new \Temma\Exceptions\Database("No audio file to transcribe.", \Temma\Exceptions\Database::FUNDAMENTAL);
		// the file name extension is used by some services to detect the audio format
		$name = $att['name'] ?? ('audio.' . $this->_audioFormat($att['mime']));
		$body = [
			'model' => $model,
			'file'  => new \CURLStringFile(base64_decode($att['data']), $name, $att['mime']),
		];
		if ($prompt !== '')
			$body['prompt'] = $prompt;
		if (isset($options['temperature']))
			$body['temperature'] = (string)(float)$options['temperature'];
		return ($body);
	}
	/**
	 * Build the content field for a chat message.
	 * @param	string	$text		Text content.
	 * @param	?array	$attachments	(optional) Processed attachments.
	 * @return	string|array	Simple string or array of content parts.
	 */
	protected function _buildContent(string $text, ?array $attachments) : string|array {
		if (!$attachments)
			return ($text);
		$content = [['type' => 'text', 'text' => $text]];
		foreach ($attachments as $att) {
			$dataUri = 'data:' . $att['mime'] . ';base64,' . $att['data'];
			if (str_starts_with($att['mime'], 'image/')) {
				$content[] = [
					'type'      => 'image_url',
					'image_url' => ['url' => $dataUri],
				];
			} else if (str_starts_with($att['mime'], 'audio/')) {
				$content[] = [
					'type'        => 'input_audio',
					'input_audio' => [
						'data'   => $att['data'],
						'format' => $this->_audioFormat($att['mime']),
					],
				];
			}
		}
		return ($content);
	}
	/**
	 * Convert MIME type to audio format identifier (also used as file extension).
	 * @param	string	$mime	MIME type.
	 * @return	string	Audio format.
	 */
	protected function _audioFormat(string $mime) : string {
		return (match ($mime) {
			'audio/wav', 'audio/x-wav'     => 'wav',
			'audio/mpeg'                   => 'mp3',
			'audio/ogg'                    => 'ogg',
			'audio/flac', 'audio/x-flac'   => 'flac',
			'audio/aac'                    => 'aac',
			'audio/mp4', 'audio/x-m4a'     => 'm4a',
			'audio/webm', 'video/webm'     => 'webm',
			'video/mp4'                    => 'mp4',
			default                        => 'mp3',
		});
	}
}
