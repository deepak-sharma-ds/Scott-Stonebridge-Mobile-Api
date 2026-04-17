<?php

namespace App\Services;

use App\Facades\Shopify;
use App\Models\CustomerEntitlement;
use App\Models\Package;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class CustomerEntitlementService
{
    /**
     * Get paginated entitlement records for the admin listing.
     */
    public function getPaginatedEntitlements(string $search = '', int $perPage = 20): LengthAwarePaginator
    {
        return CustomerEntitlement::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('email', 'like', "%{$search}%")
                        ->orWhere('shopify_customer_id', 'like', "%{$search}%")
                        ->orWhere('package_tag', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Resolve the package attached to an entitlement tag.
     */
    public function findPackageByTag(string $packageTag): ?Package
    {
        return Package::query()
            ->select('id', 'title', 'shopify_tag')
            ->where('shopify_tag', $packageTag)
            ->first();
    }

    /**
     * Add additional customer emails to an entitlement package and sync Shopify tags.
     *
     * @param array<int, string> $emails
     * @return array<string, array<int, string>>
     */
    public function addEmails(CustomerEntitlement $entitlement, array $emails): array
    {
        $storedEmails = $this->parseStoredEmails($entitlement->email);

        $summary = [
            'added' => [],
            'skipped' => [],
            'not_found' => [],
            'tag_failed' => [],
            'failed' => [],
        ];

        foreach ($emails as $email) {
            if (in_array($email, $storedEmails, true)) {
                $summary['skipped'][] = $email;
                continue;
            }

            try {
                $customer = $this->findCustomerByEmail($email);

                if (!$customer) {
                    $summary['not_found'][] = $email;
                    continue;
                }

                $customerGid = (string) data_get($customer, 'id', '');

                $storedEmails[] = $email;
                $storedEmails = array_values(array_unique($storedEmails));

                if (!$this->addTagToCustomer($customerGid, $entitlement->package_tag)) {
                    $summary['tag_failed'][] = $email;
                    $storedEmails = array_values(array_diff($storedEmails, [$email]));
                    continue;
                }

                $summary['added'][] = $email;
            } catch (\Throwable $exception) {
                Log::error('Failed to sync customer entitlement email.', [
                    'email' => $email,
                    'package_tag' => $entitlement->package_tag,
                    'entitlement_id' => $entitlement->id,
                    'error' => $exception->getMessage(),
                ]);

                $summary['failed'][] = $email;
            }
        }

        $entitlement->email = empty($storedEmails) ? null : implode(',', $storedEmails);
        $entitlement->save();

        return $summary;
    }

    /**
     * Build a compact flash message from the sync summary.
     *
     * @param array<string, array<int, string>> $summary
     */
    public function buildSyncMessage(array $summary): string
    {
        $segments = [];

        if (!empty($summary['added'])) {
            $segments[] = count($summary['added']) . ' email(s) added to the selected entitlement row.';
        }

        if (!empty($summary['skipped'])) {
            $segments[] = count($summary['skipped']) . ' email(s) were already present in the selected row.';
        }

        if (!empty($summary['not_found'])) {
            $segments[] = 'Shopify customer not found for: ' . implode(', ', $summary['not_found']) . '.';
        }

        if (!empty($summary['tag_failed'])) {
            $segments[] = 'Shopify tag sync failed for: ' . implode(', ', $summary['tag_failed']) . '.';
        }

        if (!empty($summary['failed'])) {
            $segments[] = 'Unable to process: ' . implode(', ', $summary['failed']) . '.';
        }

        return empty($segments)
            ? 'No entitlement changes were applied.'
            : implode(' ', $segments);
    }

    /**
     * Look up a Shopify customer by email using the admin API.
     */
    protected function findCustomerByEmail(string $email): ?array
    {
        $escapedEmail = addcslashes($email, "\\\"");

        $response = Shopify::query('admin', 'customers/find_customer_by_email', [
            'first' => 10,
            'query' => sprintf('email:"%s"', $escapedEmail),
        ]);

        $customers = collect(data_get($response, 'data.customers.edges', []))
            ->map(static fn(array $edge): array => $edge['node'] ?? [])
            ->filter(static fn(array $node): bool => !empty($node))
            ->values();

        if ($customers->isEmpty()) {
            return null;
        }

        $exactMatch = $customers->first(
            static fn(array $customer): bool => strcasecmp((string) data_get($customer, 'email', ''), $email) === 0
        );

        return $exactMatch ?: $customers->first();
    }

    /**
     * Add the entitlement package tag to a Shopify customer.
     */
    protected function addTagToCustomer(string $customerGid, string $tag): bool
    {
        if ($customerGid === '' || $tag === '') {
            return false;
        }

        $response = Shopify::query('admin', 'customers/add_customer_tags', [
            'id' => $customerGid,
            'tags' => [$tag],
        ]);

        $errors = data_get($response, 'data.tagsAdd.userErrors', []);

        return empty($errors);
    }

    /**
     * Parse a stored CSV email string into a normalized array.
     *
     * @return array<int, string>
     */
    protected function parseStoredEmails(?string $emails): array
    {
        if ($emails === null || trim($emails) === '') {
            return [];
        }

        $items = preg_split('/\s*,\s*/', $emails) ?: [];
        $items = array_map(static fn(string $email): string => Str::lower(trim($email)), $items);
        $items = array_filter($items, static fn(string $email): bool => $email !== '');

        return array_values(array_unique($items));
    }
}
