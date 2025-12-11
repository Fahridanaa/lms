<?php

namespace App\Http\Controllers\Traits;

trait ApiResponseTrait
{
    protected function success($data = null, $message = 'Success', $code = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function error($message = 'Error', $code = 400, $errors = null)
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

    protected function created($data = null, $message = 'Resource created successfully')
    {
        return $this->success($data, $message, 201);
    }

    protected function noContent($message = 'No content')
    {
        return $this->success(null, $message, 204);
    }

    protected function notFound($message = 'Resource not found')
    {
        return $this->error($message, 404);
    }
}
