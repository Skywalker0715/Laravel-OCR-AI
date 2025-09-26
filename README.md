# Catatan Belanja - Receipt Expense Tracker

## Overview

Catatan Belanja is a mini project built with Laravel, designed to simplify expense tracking from shopping receipts. Users can upload receipt images, which are automatically processed using OCR (Optical Character Recognition) to extract text. The extracted text is then parsed using AI (Cohere API with fallback regex parsing) to structure the data into JSON format, including vendor, date, items (with qty, price, subtotal), total amount, and change. The structured data is saved to a PostgreSQL database and managed via a user-friendly admin panel built with Filament.

This project demonstrates integration of OCR, AI parsing, queue jobs, and modern web admin interfaces for a practical expense management tool.

## Features

- **Receipt Upload & OCR**: Upload images of receipts; Tesseract OCR extracts text (supports Indonesian and English).
- **AI-Powered Parsing**: Uses Cohere AI to parse OCR text into structured JSON (vendor, date, items, total, change). Includes a robust fallback parser using regex for offline reliability.
- **Admin Panel**: Filament-based CRUD for expenses and items – create, view, edit, list with image previews and item counts.
- **Queue Processing**: Asynchronous job handling for OCR and AI parsing to avoid blocking the UI.
- **Data Storage**: PostgreSQL database with Eloquent models for Expenses and ExpenseItems.
- **User-Friendly UI**: Indonesian labels (e.g., "Judul Belanja", "Foto Struk"), image thumbnails in tables, numeric formatting for amounts.
- **Error Handling**: Logs for debugging, fallback on AI failure, validation on forms.

## Tech Stack

- **Backend**: Laravel 12 (PHP framework for API and logic).
- **Admin Panel**: Filament v3 (TALL stack: Tailwind CSS, Alpine.js, Livewire, Laravel) for forms, tables, and pages.
- **OCR**: Tesseract OCR via `thiagoalessio/tesseract_ocr` PHP wrapper (lang: 'ind,eng' for Indonesian receipts).
- **AI Parsing**: Cohere AI API (Chat endpoint with 'command' models; enhanced prompt for Indonesian struk extraction). Fallback: Custom regex/keyword parser in Helper/AIParserService.
- **Database**: PostgreSQL (migrations for users, expenses, expense_items; added vendor column).
- **Queue/Jobs**: Laravel Queue with AIParserJob for async processing (dispatch after OCR).
- **File Storage**: Laravel Filesystem (public disk for receipt images; symlink via `storage:link`).
- **Other**: 
  - Eloquent ORM for models (Expense, ExpenseItem with relations).
  - HTTP Client for Cohere API calls.
  - Logging (Laravel Log facade for OCR/AI/job debugging).
  - Vite for asset bundling (Tailwind CSS, JS).

Dependencies (from composer.json):
- `filament/filament` for admin.
- `thiagoalessio/tesseract_ocr` for OCR.
- Laravel Sanctum/Breeze for auth (if extended).

## Installation

1. **Clone the Repo**:
   ```
   git clone https://github.com/yourusername/catatan-belanja.git
   cd catatan-belanja
   ```

2. **Install Dependencies**:
   ```
   composer install
   npm install
   ```

3. **Environment Setup**:
   - Copy `.env.example` to `.env`.
   - Set database: `DB_CONNECTION=pgsql`, configure PostgreSQL credentials.
   - Add Cohere API key: `COHERE_API_KEY=your_key_here` (optional; fallback works without).
   - Generate app key: `php artisan key:generate`.

4. **Database & Migrations**:
   ```
   php artisan migrate
   php artisan db:seed  # Optional: Seed users
   ```

5. **Storage Link**:
   ```
   php artisan storage:link  # Symlink public/storage to storage/app/public
   ```

6. **Install Tesseract** (system dependency for OCR):
   - Windows: Download from [UB Mannheim](https://github.com/UB-Mannheim/tesseract/wiki), add to PATH.
   - Ensure `tesseract` executable is accessible.

7. **Run Queue Worker** (for jobs):
   ```
   php artisan queue:work  # In separate terminal
   ```

8. **Serve the App**:
   ```
   php artisan serve
   npm run dev  # For assets
   ```

Access the admin panel at `/admin` (login with seeded user or create one).

## Usage

1. **Create Expense**:
   - Go to Admin > Expenses > Create.
   - Enter title (e.g., "Belanja Bulanan").
   - Upload receipt image (JPG/PNG).
   - Submit – OCR extracts text to note, job parses and populates fields (date, amount, change, items).

2. **View List**:
   - Expenses table shows title, date, change, amount, image thumbnail (clickable), item count.
   - View/Edit individual expenses for details.

3. **Processing Flow**:
   - Upload → OCR (Tesseract extracts text) → Save note → Dispatch job.
   - Job: AI parse (Cohere or fallback) → Update Expense → Create ExpenseItems.
   - Refresh list to see parsed data (no manual input needed).

Example: Upload a struk image → Extracts "Karis Jaya Shop", date "2023-08-02", items (Indomie qty 1 @36000), total 70000, change 0 → Saves structured data.

## How It Works

1. **Form (Filament)**: Custom ExpenseForm schema with visible title/image, hidden parsed fields. FileUpload to public disk.
2. **afterCreate Hook**: In CreateExpense page – OCRService extracts text from image path, saves to note, dispatches AIParserJob.
3. **OCRService**: Uses TesseractOCR to run on image (lang 'ind,eng' for mixed text).
4. **AIParserJob**: 
   - AIParserService: HTTP to Cohere (/v1/chat, prompt for JSON: vendor/date/items/total/change).
   - cleanCohereResponse (Helper): Strips non-JSON, decodes.
   - Fallback: Regex scans lines for patterns (e.g., date \d{4}-\d{2}-\d{2}, items \d+ .+ x \d+, total/kembalian).
5. **Storage**: Updates Expense model, creates ExpenseItems via hasMany relation.
6. **Table (ExpensesTable)**: ImageColumn with getStateUsing for URL, size 120px square thumbnail; withCount('items') for count.

Logs in `storage/logs/laravel.log` for debugging (e.g., "Raw OCR text", "Fallback parsed data").

## Potential Improvements

- Integrate real Cohere key for AI (current fallback is robust but AI could handle complex layouts better).
- Add user auth/roles in Filament.
- Export reports (PDF/CSV of expenses).
- Improve OCR accuracy (pre-process images with ImageMagick).
- Mobile upload via API endpoint.
- Tests: Add Pest/PHPUnit for services/jobs.

## Contributing

1. Fork the repo.
2. Create branch: `git checkout -b feature/your-feature`.
3. Commit: `git commit -m "Add feature"`.
4. Push: `git push origin feature/your-feature`.
5. Open PR to main.

Report issues or suggest enhancements!

## License

MIT License – feel free to use/modify.

---

Built with ❤️ for expense tracking. Questions? Open an issue!
