<?php

namespace App\Traits;

trait ShopifyResponseFormatter
{
    /**
     * Parse/Paginate edges from a Shopify GraphQL response
     */
    public function parseEdges(array $data, string $key)
    {
        $edges = data_get($data, "$key.edges", []);

        return [
            'items' => array_column($edges, 'node'),
            'next_cursor' => data_get($data, "$key.pageInfo.endCursor"),
            'has_more' => data_get($data, "$key.pageInfo.hasNextPage"),
        ];
    }

    /**
     * Standard success response
     */
    public function success($msg, $data = [])
    {
        return response()->json([
            'status' => 200,
            'message' => $msg,
            'data' => $data
        ], 200);
    }

    /**
     * Standard failure response
     */
    public function fail($msg, $error = null, $code = 500)
    {
        return response()->json([
            'status' => $code,
            'message' => $msg,
            'error' => $error
        ], $code);
    }
}
