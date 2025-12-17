<?php

namespace App\Http\Controllers\Traits;

use App\Constants\ResponseMessage;

trait ApiResponseTrait
{
    protected function success($data = null, $message = ResponseMessage::SUCCESS, $code = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function error($message = ResponseMessage::SERVER_ERROR, $code = 400, $errors = null)
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    protected function created($data = null, $message = ResponseMessage::CREATED)
    {
        return $this->success($data, $message, 201);
    }

    protected function noContent($message = ResponseMessage::NO_CONTENT)
    {
        return $this->success(null, $message, 204);
    }

    protected function notFound($message = ResponseMessage::NOT_FOUND)
    {
        return $this->error($message, 404);
    }
}
