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

    /**
     * Generate Star Document Markup for thermal printer
     */
    protected function renderStarMarkup(array $data): string
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
