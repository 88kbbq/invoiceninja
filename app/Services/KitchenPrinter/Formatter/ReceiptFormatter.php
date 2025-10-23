<?php

namespace App\Services\KitchenPrinter\Formatter;

use App\Models\Invoice;
use App\Models\Quote;
use Carbon\Carbon;

class ReceiptFormatter
{
    /**
     * Format an invoice for WebPRNT transport.
     */
    public function formatInvoiceForWebPrnt(Invoice $invoice): string
    {
        $data = $this->prepareInvoiceData($invoice);

        return $this->renderWebPrnt($data);
    }

    /**
     * Format a quote for WebPRNT transport.
     */
    public function formatQuoteForWebPrnt(Quote $quote): string
    {
        $data = $this->prepareQuoteData($quote);

        return $this->renderWebPrnt($data);
    }

    /**
     * Format an invoice for raw TCP transport.
     */
    public function formatInvoiceForTcp(Invoice $invoice): string
    {
        $data = $this->prepareInvoiceData($invoice);

        return $this->renderTcp($data);
    }

    /**
     * Format a quote for raw TCP transport.
     */
    public function formatQuoteForTcp(Quote $quote): string
    {
        $data = $this->prepareQuoteData($quote);

        return $this->renderTcp($data);
    }

    /**
     * Build a simple WebPRNT test ticket.
     */
    public function buildWebPrntTestTicket(): string
    {
        $now = Carbon::now()->format('Y-m-d H:i:s');

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<StarWebPRNT xmlns="http://www.star-m.jp">
  <Request>
    <Contents>
      <initialization/>
      <alignment value="center"/>
      <text emphasis="true" width="2" height="2">Kitchen Printer Test</text>
      <lineFeed/>
      <text>WebPRNT connectivity check</text>
      <lineFeed/>
      <alignment value="left"/>
      <ruledLine thickness="thick"/>
      <lineFeed/>
      <text>Timestamp: {$now}</text>
      <lineFeed/>
      <cut type="partial"/>
    </Contents>
  </Request>
</StarWebPRNT>
XML;

        return $xml;
    }

    /**
     * Build a raw TCP test ticket (Big5 encoded).
     */
    public function buildTcpTestTicket(string $ip, int $port): string
    {
        $now = Carbon::now()->format('Y-m-d H:i:s');

        $ticket = "";
        $ticket .= chr(27) . chr(64); // ESC @ - Initialize printer
        $ticket .= "================================\n";
        $ticket .= "  KITCHEN PRINTER TEST\n";
        $ticket .= "================================\n\n";
        $ticket .= "Invoice Ninja Kitchen Printer\n";
        $ticket .= "TCP/IP Direct Printing\n\n";
        $ticket .= "Printer IP: {$ip}\n";
        $ticket .= "Port: {$port}\n";
        $ticket .= "Time: {$now}\n\n";
        $ticket .= "If you see this, printing works!\n\n";
        $ticket .= "================================\n";
        $ticket .= "\n\n\n\n\n\n";
        $ticket .= chr(12); // Form feed
        $ticket .= chr(29) . chr(86) . chr(0); // Full cut

        return $this->toBig5($ticket);
    }

    /**
     * Prepare common invoice data for renderers.
     */
    protected function prepareInvoiceData(Invoice $invoice): array
    {
        $include = config('kitchenprinter.include', []);

        return [
            'type' => 'INVOICE',
            'number' => $invoice->number,
            'date' => $this->formatDate($invoice->date),
            'due_date' => $this->shouldInclude($include, 'due_date') ? $this->formatDate($invoice->due_date) : null,
            'event_time' => $this->shouldInclude($include, 'event_time') ? $invoice->custom_value1 : null,
            'event_date' => $this->shouldInclude($include, 'event_date') ? $invoice->custom_value2 : null,
            'client_name' => $this->shouldInclude($include, 'client_name') ? optional($invoice->client)->present()->name() : null,
            'client_phone' => $this->shouldInclude($include, 'client_phone') ? optional($invoice->client)->phone : null,
            'items' => $this->formatLineItems($invoice->line_items),
            'public_notes' => $invoice->public_notes,
            'private_notes' => $invoice->private_notes,
            'printed_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Prepare common quote data for renderers.
     */
    protected function prepareQuoteData(Quote $quote): array
    {
        $include = config('kitchenprinter.include', []);

        return [
            'type' => 'QUOTE',
            'number' => $quote->number,
            'date' => $this->formatDate($quote->date),
            'due_date' => $this->shouldInclude($include, 'due_date') ? $this->formatDate($quote->due_date) : null,
            'event_time' => $this->shouldInclude($include, 'event_time') ? $quote->custom_value1 : null,
            'event_date' => $this->shouldInclude($include, 'event_date') ? $quote->custom_value2 : null,
            'client_name' => $this->shouldInclude($include, 'client_name') ? optional($quote->client)->present()->name() : null,
            'client_phone' => $this->shouldInclude($include, 'client_phone') ? optional($quote->client)->phone : null,
            'items' => $this->formatLineItems($quote->line_items),
            'public_notes' => $quote->public_notes,
            'private_notes' => $quote->private_notes,
            'printed_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Render prepared data as WebPRNT XML.
     */
    protected function renderWebPrnt(array $data): string
    {
        $commands = [];

        $commands[] = '<initialization/>';
        $commands[] = '<alignment value="center"/>';

        if (!empty($data['number'])) {
            $commands[] = $this->webPrntText($data['number'], true, 2, 2);
            $commands[] = '<lineFeed/>';
        }

        if (!empty($data['date'])) {
            $commands[] = $this->webPrntText('日期: ' . $data['date'], false, 2, 2);
            $commands[] = '<lineFeed/>';
        }

        if (!empty($data['event_time'])) {
            $commands[] = $this->webPrntText('到達時間: ' . $data['event_time'], false, 2, 2);
            $commands[] = '<lineFeed/>';
        }

        $commands[] = '<alignment value="left"/>';
        $commands[] = '<ruledLine thickness="thick"/>';
        $commands[] = '<lineFeed/>';

        if (!empty($data['client_name'])) {
            $commands[] = $this->webPrntText('客戶: ' . $data['client_name'], true, 2, 2);
            $commands[] = '<lineFeed/>';
        }

        if (!empty($data['client_phone'])) {
            $commands[] = $this->webPrntText('電話: ' . $data['client_phone'], false, 2, 2);
            $commands[] = '<lineFeed/>';
        }

        $commands[] = '<ruledLine thickness="thick"/>';
        $commands[] = '<lineFeed/>';

        foreach ($data['items'] as $item) {
            $name = $item['product'];
            $qty = $this->formatQuantity($item['quantity']);

            $commands[] = $this->webPrntText($name . '  ', false, 2, 2);
            $commands[] = '<text emphasis="true" invert="true" width="2" height="2"> ' . $this->escapeXml($qty) . ' </text>';
            $commands[] = '<lineFeed/>';
        }

        $commands[] = '<ruledLine thickness="thick"/>';
        $commands[] = '<lineFeed/>';

        if (!empty($data['public_notes'])) {
            $commands[] = '<text>' . $this->escapeXml($data['public_notes']) . '</text>';
            $commands[] = '<lineFeed/>';
        }

        if (!empty($data['private_notes'])) {
            $commands[] = '<text emphasis="true">' . $this->escapeXml($data['private_notes']) . '</text>';
            $commands[] = '<lineFeed/>';
        }

        $commands[] = '<text>列印時間: ' . $this->escapeXml($data['printed_at']) . '</text>';
        $commands[] = '<lineFeed/>';
        $commands[] = '<cut type="partial"/>';

        $payload = implode("\n      ", $commands);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<StarWebPRNT xmlns="http://www.star-m.jp">' . "\n";
        $xml .= '  <Request>' . "\n";
        $xml .= '    <Contents>' . "\n";
        $xml .= '      ' . $payload . "\n";
        $xml .= '    </Contents>' . "\n";
        $xml .= '  </Request>' . "\n";
        $xml .= '</StarWebPRNT>';

        return $xml;
    }

    /**
     * Render prepared data as raw TCP commands (Big5 encoded).
     */
    protected function renderTcp(array $data): string
    {
        $output = '';
        $output .= chr(27) . chr(64); // Initialize printer

        // Star Line Mode font sizes: ESC i a n (width multiplier)
        $font3x = chr(27) . chr(105) . chr(1) . chr(2); // 3x width (invoice # only)
        $font2x = chr(27) . chr(105) . chr(1) . chr(1); // 2x width (items, date, time)
        $font1x = chr(27) . chr(105) . chr(1) . chr(0); // 1x width (customer, notes)

        // Invoice/Quote number - 3x font (largest)
        if (!empty($data['number'])) {
            $output .= $font3x;
            $output .= $this->toBig5($data['number']) . "\n\n";
        }

        // Date - 2x font
        if (!empty($data['date'])) {
            $output .= $font2x;
            $output .= $this->toBig5('日期: ' . $data['date']) . "\n";
        }

        // Event time - 2x font
        if (!empty($data['event_time'])) {
            $output .= $font2x;
            $output .= $this->toBig5('到達時間: ' . $data['event_time']) . "\n";
        }

        // Separator
        $output .= $font1x;
        $output .= str_repeat('-', 48) . "\n";

        // Client name - 1x font
        if (!empty($data['client_name'])) {
            $output .= $font1x;
            $output .= $this->toBig5('客戶: ' . $data['client_name']) . "\n";
        }

        // Client phone - 1x font
        if (!empty($data['client_phone'])) {
            $output .= $font1x;
            $output .= $this->toBig5('電話: ' . $data['client_phone']) . "\n";
        }

        // Separator
        $output .= $font1x;
        $output .= str_repeat('-', 48) . "\n";

        // Line items - 2x font with columnar layout
        // At 2x width, Star mC-Print3 has ~24 display positions per line
        // Chinese chars = 2 positions, English/numbers = 1 position
        $output .= $font2x;
        foreach ($data['items'] as $item) {
            $name = $item['product'];
            $qty = $this->formatQuantity($item['quantity']);

            // Calculate display width (Chinese = 2, ASCII = 1)
            $maxDisplayWidth = 18; // Leave room for quantity
            $qtyDisplayWidth = strlen($qty);

            // Truncate name to fit within display width
            $nameDisplayWidth = $this->calculateDisplayWidth($name);
            if ($nameDisplayWidth > $maxDisplayWidth) {
                $name = $this->truncateToDisplayWidth($name, $maxDisplayWidth - 1) . '…';
                $nameDisplayWidth = $this->calculateDisplayWidth($name);
            }

            // Calculate padding needed (in half-width spaces)
            $totalWidth = 22; // Total display positions available
            $paddingSpaces = $totalWidth - $nameDisplayWidth - $qtyDisplayWidth;
            $paddingSpaces = max(1, $paddingSpaces); // At least 1 space

            $line = $name . str_repeat(' ', $paddingSpaces) . $qty;

            $output .= $this->toBig5($line) . "\n";
        }

        // Separator
        $output .= $font1x;
        $output .= str_repeat('-', 48) . "\n";

        // Notes - 1x font
        if (!empty($data['public_notes'])) {
            $output .= $font1x;
            $output .= $this->toBig5($data['public_notes']) . "\n";
        }

        if (!empty($data['private_notes'])) {
            $output .= chr(27) . chr(69); // Emphasis on (Star Line Mode)
            $output .= $this->toBig5($data['private_notes']) . "\n";
            $output .= chr(27) . chr(70); // Emphasis off (Star Line Mode)
        }

        // Print time - 1x font
        $output .= $font1x;
        $output .= $this->toBig5('列印時間: ' . $data['printed_at']) . "\n";
        $output .= "\n\n\n";
        $output .= chr(27) . chr(100) . chr(1); // Partial cut

        return $output; // Don't call toBig5() on entire output - it corrupts control codes!
    }

    /**
     * Convert UTF-8 text to Big5 encoding where possible.
     */
    protected function toBig5(string $text): string
    {
        if (strtolower((string) config('kitchenprinter.encoding', 'big5')) !== 'big5') {
            return $text;
        }

        $converted = @iconv('UTF-8', 'BIG5//IGNORE', $text);

        return $converted !== false ? $converted : $text;
    }

    /**
     * Produce a WebPRNT text element.
     */
    protected function webPrntText(string $text, bool $emphasis = false, int $width = 1, int $height = 1): string
    {
        $attributes = [
            'width' => (string) max(1, $width),
            'height' => (string) max(1, $height),
        ];

        if ($emphasis) {
            $attributes['emphasis'] = 'true';
        }

        $parts = [];
        foreach ($attributes as $key => $value) {
            $parts[] = $key . '="' . $value . '"';
        }

        $attr = implode(' ', $parts);

        return '<text ' . $attr . '>' . $this->escapeXml($text) . '</text>';
    }

    /**
     * Ensure optional config flag is truthy.
     */
    protected function shouldInclude(array $include, string $key): bool
    {
        return array_key_exists($key, $include) ? (bool) $include[$key] : true;
    }

    /**
     * Format a star line item quantity.
     */
    protected function formatQuantity($quantity): string
    {
        if (!is_numeric($quantity)) {
            return (string) $quantity;
        }

        return floor((float) $quantity) == (float) $quantity
            ? number_format((float) $quantity, 0)
            : rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.');
    }

    /**
     * Prepare line items for rendering.
     */
    protected function formatLineItems($lineItems): array
    {
        $items = [];

        foreach ((array) $lineItems as $item) {
            $name = $item->product_key ?: $item->notes;

            if (empty($name) && empty($item->notes)) {
                continue;
            }

            $items[] = [
                'product' => $name,
                'quantity' => $item->quantity,
            ];
        }

        return $items;
    }

    /**
     * Safely escape XML text.
     */
    protected function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    /**
     * Format a date string defensively.
     */
    protected function formatDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Calculate display width of string (Chinese = 2, ASCII = 1).
     */
    protected function calculateDisplayWidth(string $text): int
    {
        $width = 0;
        $length = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');

            // Check if character is full-width (Chinese, Japanese, Korean, etc.)
            if (preg_match('/[\x{4E00}-\x{9FFF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{FF00}-\x{FFEF}]/u', $char)) {
                $width += 2; // Full-width character
            } else {
                $width += 1; // Half-width character
            }
        }

        return $width;
    }

    /**
     * Truncate string to fit within display width.
     */
    protected function truncateToDisplayWidth(string $text, int $maxWidth): string
    {
        $result = '';
        $currentWidth = 0;
        $length = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');

            // Calculate character width
            if (preg_match('/[\x{4E00}-\x{9FFF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{FF00}-\x{FFEF}]/u', $char)) {
                $charWidth = 2; // Full-width
            } else {
                $charWidth = 1; // Half-width
            }

            // Check if adding this character would exceed max width
            if ($currentWidth + $charWidth > $maxWidth) {
                break;
            }

            $result .= $char;
            $currentWidth += $charWidth;
        }

        return $result;
    }
}
