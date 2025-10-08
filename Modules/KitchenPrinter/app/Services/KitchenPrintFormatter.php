<?php

namespace Modules\KitchenPrinter\Services;

use App\Models\Invoice;
use App\Models\Quote;

class KitchenPrintFormatter
{
    /**
     * Format invoice for kitchen receipt using Star Document Markup
     */
    public function formatInvoice(Invoice $invoice): string
    {
        $data = $this->prepareInvoiceData($invoice);
        return $this->renderStarMarkup($data);
    }

    /**
     * Format quote for kitchen receipt
     */
    public function formatQuote(Quote $quote): string
    {
        $data = $this->prepareQuoteData($quote);
        return $this->renderStarMarkup($data);
    }

    protected function prepareInvoiceData(Invoice $invoice): array
    {
        $include = config('kitchenprinter.include', []);

        return [
            'type' => 'INVOICE',
            'number' => $invoice->number,
            'due_date' => $include['due_date'] ? $invoice->due_date : null,
            'event_time' => $include['event_time'] ? $invoice->custom_value1 : null,
            'event_date' => $include['event_time'] ? $invoice->custom_value2 : null,
            'client_name' => $include['client_name'] ? $invoice->client->name : null,
            'client_phone' => $include['client_phone'] ? $invoice->client->phone : null,
            'shipping_address' => $this->formatShippingAddress($invoice->client),
            'items' => $this->formatLineItems($invoice->line_items),
            'notes' => $include['public_notes'] ? $invoice->public_notes : null,
            'printed_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    protected function prepareQuoteData(Quote $quote): array
    {
        $include = config('kitchenprinter.include', []);

        return [
            'type' => 'QUOTE',
            'number' => $quote->number,
            'due_date' => $include['due_date'] ? $quote->due_date : null,
            'event_time' => $include['event_time'] ? $quote->custom_value1 : null,
            'event_date' => $include['event_time'] ? $quote->custom_value2 : null,
            'client_name' => $include['client_name'] ? $quote->client->name : null,
            'client_phone' => $include['client_phone'] ? $quote->client->phone : null,
            'shipping_address' => $this->formatShippingAddress($quote->client),
            'items' => $this->formatLineItems($quote->line_items),
            'notes' => $include['public_notes'] ? $quote->public_notes : null,
            'printed_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    protected function formatLineItems($lineItems): array
    {
        $items = [];

        foreach ($lineItems as $item) {
            $items[] = [
                'product' => $item->product_key ?: $item->notes,
                'quantity' => $item->quantity,
                'notes' => $item->notes,
            ];
        }

        return $items;
    }

    protected function formatShippingAddress($client): ?string
    {
        if (!$client) {
            return null;
        }

        $parts = array_filter([
            $client->shipping_address1,
            $client->shipping_address2,
            $client->shipping_city,
            $client->shipping_state,
            $client->shipping_postal_code,
        ]);

        return !empty($parts) ? implode(', ', $parts) : null;
    }

    /**
     * Generate Star Document Markup for thermal printer
     * Template optimized for Star mC-Print3 (80mm paper width)
     */
    protected function renderStarMarkup(array $data): string
    {
        $template = config('kitchenprinter.template', 'default');

        // Use mC-Print3 optimized template
        if ($template === 'mC-Print3') {
            return $this->renderMcPrint3Template($data);
        }

        // Default/fallback template
        return $this->renderDefaultTemplate($data);
    }

    /**
     * mC-Print3 optimized template (80mm paper, compact layout)
     * Format: Row 0: Invoice number (large)
     *         Row 1: Contact name (large)
     *         Row 2: Phone
     *         Row 3: Event time + Due date
     *         Row 4: Shipping address
     *         Items: Product & Quantity (one per row, large text)
     */
    protected function renderMcPrint3Template(array $data): string
    {
        $markup = "";

        // Paper width setup for 80mm thermal paper
        $markup .= "[papertype: normal; width 80]\n";
        $markup .= "[align: left]\n";

        // Row 0: Invoice number (1.5x size, bold)
        $invoice = $data['number'] ?? 'N/A';
        $markup .= "[magnify: width 2; height 1]\n";
        $markup .= "[bold: on]#{$invoice}[bold: off]\n";
        $markup .= "[magnify: width 1; height 1]\n";

        // Row 1: Contact name (1.5x size, bold)
        $name = trim(($data['client_name'] ?? 'Guest'));
        $markup .= "[magnify: width 2; height 1]\n";
        $markup .= "[bold: on]{$name}[bold: off]\n";
        $markup .= "[magnify: width 1; height 1]\n";

        // Row 2: Phone number (regular size)
        if (!empty($data['client_phone'])) {
            $markup .= "{$data['client_phone']}\n";
        }

        // Row 3: Event time (custom1) and due date (regular size)
        $row3_parts = [];
        if (!empty($data['event_time'])) {
            $row3_parts[] = $data['event_time'];
        }
        if (!empty($data['due_date'])) {
            $row3_parts[] = "Due: {$data['due_date']}";
        }
        if (!empty($row3_parts)) {
            $markup .= implode('  ', $row3_parts) . "\n";
        }

        // Row 4: Shipping address (regular size)
        if (!empty($data['shipping_address'])) {
            $markup .= "{$data['shipping_address']}\n";
        }

        // Separator
        $markup .= "--------------------------------\n";

        // Items list: Product and Quantity (1.5x size, bold for qty)
        foreach ($data['items'] as $item) {
            $qty = $item['quantity'];
            $product = $item['product'];

            // Format: "Qty x Product Name" (larger text)
            $markup .= "[magnify: width 2; height 1]\n";
            $markup .= "[bold: on]{$qty}x[bold: off] {$product}\n";
            $markup .= "[magnify: width 1; height 1]\n";

            // Optional: Show notes indented (regular size)
            if (!empty($item['notes']) && $item['notes'] !== $product) {
                $markup .= "  {$item['notes']}\n";
            }
        }

        // Footer
        $markup .= "--------------------------------\n";

        // Optional: Public notes (regular size)
        if (!empty($data['notes'])) {
            $markup .= "Notes: {$data['notes']}\n";
            $markup .= "--------------------------------\n";
        }

        // Print timestamp (regular size, centered)
        $markup .= "[align: center]\n";
        $markup .= "Printed: {$data['printed_at']}\n\n";

        // Cut paper
        $markup .= "[cut: feed; partial]\n";

        return $markup;
    }

    /**
     * Default/legacy template for backwards compatibility
     */
    protected function renderDefaultTemplate(array $data): string
    {
        $markup = "[magnify: width 2; height 2]\n";
        $markup .= "[align: center]\n";
        $markup .= "*** KITCHEN ORDER ***\n";
        $markup .= "[magnify: width 1; height 1]\n";
        $markup .= "[align: left]\n";
        $markup .= "--------------------------------\n";
        $markup .= "Order #: {$data['number']}\n";
        $markup .= "Type: {$data['type']}\n";
        $markup .= "Time: {$data['printed_at']}\n";

        if (!empty($data['event_time'])) {
            $markup .= "Event Time: {$data['event_time']}\n";
        }

        if (!empty($data['event_date'])) {
            $markup .= "Event Date: {$data['event_date']}\n";
        }

        if (!empty($data['due_date'])) {
            $markup .= "Due Date: {$data['due_date']}\n";
        }

        $markup .= "--------------------------------\n";

        if (!empty($data['client_name'])) {
            $markup .= "Customer: {$data['client_name']}\n";
        }

        if (!empty($data['client_phone'])) {
            $markup .= "Phone: {$data['client_phone']}\n";
        }

        $markup .= "--------------------------------\n";
        $markup .= "[magnify: width 1; height 2]\n";
        $markup .= "ITEMS:\n";
        $markup .= "[magnify: width 1; height 1]\n";

        foreach ($data['items'] as $item) {
            $qty = $item['quantity'];
            $product = $item['product'];

            $markup .= "[bold: on]\n";
            $markup .= "{$qty}x {$product}\n";
            $markup .= "[bold: off]\n";

            if (!empty($item['notes'])) {
                $markup .= "   {$item['notes']}\n";
            }
            $markup .= "\n";
        }

        $markup .= "--------------------------------\n";

        if (!empty($data['notes'])) {
            $markup .= "NOTES:\n";
            $markup .= $data['notes'] . "\n";
            $markup .= "--------------------------------\n";
        }

        $markup .= "[align: center]\n";
        $markup .= "*** END OF ORDER ***\n\n\n";
        $markup .= "[cut: feed; partial]\n";

        return $markup;
    }
}
