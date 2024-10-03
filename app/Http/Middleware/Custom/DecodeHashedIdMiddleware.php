<?php

namespace App\Http\Middleware\Custom;

use App\Services\HashIdService;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DecodeHashedIdMiddleware
{
    protected $idLength;

    public function __construct()
    {
        $this->idLength = env('SQIDS_LENGTH', 10); // Default ke 10 jika tidak ada SQIDS_LENGTH
    }

    public function handle($request, Closure $next)
    {
        if ($request->route()) {
            $routeParameters = $request->route()->parameters();

            foreach ($routeParameters as $key => $value) {
                if ($this->isIdKey($key)) {
                    try {
                        $cleanedValue = $this->cleanInput($value);

                        if ($this->isInvalidInteger($cleanedValue)) {
                            Log::error('Invalid integer ID detected:', [
                                'key' => $key,
                                'value' => $cleanedValue
                            ]);
                            return response()->json(['error' => 'Invalid ID format.'], 400);
                        }

                        if (!$this->isValidIdLength($cleanedValue)) {
                            Log::error('Invalid ID length:', [
                                'key' => $key,
                                'value' => $cleanedValue,
                                'expected_length' => $this->idLength
                            ]);
                            return response()->json(['error' => 'Invalid ID length.'], 400);
                        }

                        $decodedId = $this->attemptDecode($cleanedValue);

                        $request->route()->setParameter($key, $decodedId);

                        Log::info('Decoded route parameter ID:', [
                            'key' => $key,
                            'original' => $value,
                            'decoded' => $decodedId
                        ]);
                    } catch (HttpException $e) {
                        Log::error('Failed to decode route parameter ID (user error):', [
                            'key' => $key,
                            'value' => $value,
                            'error' => $e->getMessage()
                        ]);
                        return response()->json(['error' => $e->getMessage()], $e->getStatusCode());
                    } catch (Exception $e) {
                        Log::critical('Failed to decode route parameter ID (system error):', [
                            'key' => $key,
                            'value' => $value,
                            'error' => $e->getMessage()
                        ]);
                        return response()->json(['error' => 'Internal Server Error while decoding ID for ' . $key], 500);
                    }
                }
            }
        }

        $requestMethod = $request->method();
        Log::info('Incoming Request Method: ' . $requestMethod);

        if (in_array($requestMethod, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $requestData = $request->all();
            $decodedRequest = $this->decodeIdsInRequest($requestData);

            if (is_array($decodedRequest)) {
                $request->replace($decodedRequest);
            }
        }

        return $next($request);
    }

    protected function cleanInput($input)
    {
        return trim($input);
    }

    protected function isInvalidInteger($value)
    {
        return is_numeric($value) && (int)$value == $value;
    }

    protected function isValidIdLength($value)
    {
        return strlen($value) == $this->idLength;
    }

    protected function attemptDecode($value)
    {
        if (!is_scalar($value)) {
            return response()->json(['error' => 'Cannot decode non-scalar value: ' . json_encode($value)], 400);
        }

        try {
            $decoded = $this->decodeId($value);

            if (empty($decoded)) {
                return response()->json(['error' => 'Decoding failed for value: ' . $value], 400);
            }

            return $decoded[0];
        } catch (Exception $e) {
            return response()->json(['error' => 'Decoding error due to system failure: ' . $e->getMessage()], 500);
        }
    }

    protected function decodeId($hashedId)
    {
        return app(HashIdService::class)->decodeId($hashedId);
    }

    protected function isIdKey($key)
    {
        return preg_match('/id$/i', $key) || preg_match('/ids$/i', $key);
    }

    protected function decodeIdsInRequest(array $input)
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->flattenArray(array_map(function ($item) use ($key) {
                    return is_array($item) ? $this->decodeIdsInRequest($item) : $this->decodeSingleValue($key, $item);
                }, $value));
            } elseif ($this->isJsonString($value)) {
                $decodedJson = json_decode($value, true);
                if (is_array($decodedJson)) {
                    $input[$key] = $this->decodeIdsInRequest($decodedJson);
                } else {
                    $input[$key] = $this->decodeSingleValue($key, $value);
                }
            } else {
                if ($this->isIdKey($key)) {
                    $input[$key] = $this->decodeSingleValue($key, $value);
                }
            }
        }

        return $input;
    }

    protected function decodeSingleValue($key, $value)
    {
        if ($this->isIdKey($key) && is_scalar($value)) {
            try {
                if (strpos($value, ',') !== false) {
                    $ids = explode(',', $value);
                    $decodedIds = array_map(function ($id) {
                        return (int) $this->attemptDecode(trim($id));
                    }, $ids);

                    return $decodedIds;
                }

                $decodedValue = $this->attemptDecode($value);

                if (is_int($decodedValue)) {
                    return (int) $decodedValue;
                }
            } catch (HttpException $e) {
                Log::error('Failed to decode ID value (user error):', [
                    'key' => $key,
                    'value' => $value,
                    'error' => $e->getMessage()
                ]);
                return response()->json(['error' => $e->getMessage()], $e->getStatusCode());
            } catch (Exception $e) {
                Log::critical('Failed to decode ID value (system error):', [
                    'key' => $key,
                    'value' => $value,
                    'error' => $e->getMessage()
                ]);
                return response()->json(['error' => 'Internal Server Error while decoding ID for ' . $key], 500);
            }
        }

        return $value;
    }

    protected function isJsonString($value)
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    protected function flattenArray(array $array)
    {
        $result = [];
        array_walk_recursive($array, function ($a) use (&$result) {
            $result[] = $a;
        });
        return $result;
    }
}
