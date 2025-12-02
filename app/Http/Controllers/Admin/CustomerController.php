<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Facades\Shopify;
use App\Traits\ShopifyResponseFormatter;
use Exception;

class CustomerController extends Controller
{
    use ShopifyResponseFormatter;

    /**
     * Show customer listing page
     */
    public function index(Request $request)
    {
        $request->validate([
            'limit' => 'sometimes|integer|min:1|max:50',
            'after' => 'sometimes|string|nullable',
            'search' => 'sometimes|string|nullable',
            'filter' => 'sometimes|string|nullable',
        ]);


        try {
            $vars = [
                'first' => $request->limit ?? 20,
                'after' => $request->after ?? null,
                'query' => $this->buildQueryString($request),
            ];

            $response = Shopify::query(
                'admin',
                'customers/list_customers',
                $vars
            );

            $parsed = $this->parseEdges(
                data_get($response, 'data'),
                'customers'
            );

            return view('admin.customers.index', [
                'customers' => $parsed['items'],
                'nextCursor' => $parsed['next_cursor'],
                'hasNextPage' => $parsed['has_more'],
                'request' => $request,
            ]);
        } catch (Exception $e) {
            return back()->with('error', "Failed to fetch customers: " . $e->getMessage());
        }
    }

    /**
     * Build Shopify search query string
     */
    private function buildQueryString(Request $req)
    {
        $query = [];

        if ($req->search) {
            $q = $req->search;
            $query[] = "(email:*{$q}* OR name:*{$q}* OR phone:*{$q}*)";
        }

        if ($req->filter === 'inactive') {
            $query[] = "orders_count:0";
        }

        if ($req->filter === 'active') {
            $query[] = "orders_count:>0";
        }

        if (empty($query)) {
            return null;
        }

        return implode(" AND ", $query);
    }

    /**
     * Customer details page
     */
    public function show($id)
    {
        try {
            $response = Shopify::query(
                'admin',
                'customers/get_customer',
                ['id' => "gid://shopify/Customer/{$id}"]
            );

            $customer = data_get($response, 'data.customer');

            return view('admin.customers.show', compact('customer'));
        } catch (Exception $e) {
            return back()->with('error', "Failed to load customer details.");
        }
    }

    /**
     * Suspend Customer
     */
    public function suspend($id)
    {
        try {
            Shopify::query(
                'admin',
                'customers/suspend_customer',
                [
                    'ownerId' => "gid://shopify/Customer/{$id}",
                    'value' => "1"
                ]
            );

            return back()->with('success', 'Customer suspended successfully.');
        } catch (Exception $e) {
            return back()->with('error', 'Failed to suspend customer.');
        }
    }

    /**
     * Unsuspend Customer
     */
    public function unsuspend($id)
    {
        try {
            Shopify::query(
                'admin',
                'customers/suspend_customer',
                [
                    'ownerId' => "gid://shopify/Customer/{$id}",
                    'value' => "0"
                ]
            );

            return back()->with('success', 'Customer unsuspended.');
        } catch (Exception $e) {
            return back()->with('error', 'Failed to update customer.');
        }
    }

    private function suspendMetafield($id, $value)
    {
        try {
            $response = Shopify::query(
                'admin',
                'customers/suspend_customer',
                [
                    'ownerId' => "gid://shopify/Customer/{$id}",
                    'value' => $value,
                ]
            );

            return $this->success("Updated suspend status", $response);
        } catch (Exception $e) {
            return $this->fail("Failed to update suspension", $e->getMessage());
        }
    }

    /**
     * Delete customer
     */
    public function destroy($id)
    {
        try {
            Shopify::query(
                'admin',
                'customers/delete_customer',
                ['id' => "gid://shopify/Customer/{$id}"]
            );

            return redirect()->route('admin.customers.index')->with('success', 'Customer deleted.');
        } catch (Exception $e) {
            return back()->with('error', 'Failed to delete customer.');
        }
    }

    /**
     * Export CSV
     */
    public function exportCsv(Request $request)
    {
        try {
            // Fetch again with bigger limit
            $vars = [
                'first' => $request->limit ?? 20,
                'after' => $request->after ?? null,
                'query' => null,
            ];
            $response = Shopify::query(
                'admin',
                'customers/list_customers',
                $vars
            );
            $parsed = $this->parseEdges(data_get($response, 'data'), 'customers');
            $filename = 'customers_export_' . date('Y-m-d') . '.csv';

            $handle = fopen($filename, 'w+');
            fputcsv($handle, ['Name', 'Email', 'Orders Count', 'Total Spent']);

            foreach ($parsed['items'] as $c) {
                fputcsv($handle, [
                    $c['firstName'] . ' ' . $c['lastName'],
                    $c['email'],
                    $c['numberOfOrders'],
                    $c['amountSpent']['amount'] . ' (' . $c['amountSpent']['currencyCode'] . ')'
                ]);
            }

            fclose($handle);

            return response()->download($filename)->deleteFileAfterSend(true);
        } catch (\Throwable $th) {
            dd($th);
            //throw $th;
        }
    }
}
