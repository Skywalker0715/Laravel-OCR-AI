# TODO: Fix Helper Import and Declaration Errors

- [x] Update app/Filament/Resources/Expenses/Pages/CreateExpense.php: Change use App\Helpers\Helper; to use App\Services\Helper; and type the property as private Helper $helper;
- [ ] Update app/Jobs/AIParserJob.php: Add use App\Services\Helper; and declare private Helper $helper;
- [ ] Update app/Services/Helper.php: Add the missing method extractSpecialFieldsAndCleanItems with placeholder implementation.
- [ ] Test the application to ensure errors are resolved.
