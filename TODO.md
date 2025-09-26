# TODO: Improve Receipt Parsing for Indonesian Struk

## Steps to Complete:

### 1. Verify/Add 'vendor' Field in Expense Model
- [x] Read app/Models/Expense.php to check if 'vendor' fillable/casts.
- [x] Created and ran migration to add 'vendor' column.
- [x] Added to $fillable.

### 2. Update Helper.php
- [x] Added extractSpecialFieldsAndCleanItems to scan for 'kembalian' keywords, extract numeric value, clean items.

### 3. Update AIParserService.php
- [x] Improved prompt: Added instructions for vendor, change, currency/dates; included Karis Jaya Shop example.
- [x] Updated JSON structure to include "vendor" and "change".
- [x] Added Log::info for raw OCR text and raw AI response.

### 4. Update AIParserJob.php
- [x] Added $this->record->vendor = $parsed['vendor'] ?? null;
- [x] Added Log::info for raw $this->record->note and full $parsed.
- [x] Updated change to use $parsed['change'] ?? Helper extraction.

### 5. Create and Run Migration (if needed)
- [x] Created migration for add_vendor_to_expenses_table.
- [x] Edited to add nullable string 'vendor' column.
- [x] Ran php artisan migrate.

### 6. Testing
- [ ] Create test Expense with OCR text from receipt image.
- [ ] Dispatch AIParserJob manually or via queue.
- [ ] Check database entries and logs for accuracy.
- [ ] Update TODO.md with results.

Progress: Starting with Step 1.
