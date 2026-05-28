@echo off
:: Настройка путей XAMPP и Облака (Поменяй путь к облаку на свой!)
set "MYSQL_BIN=C:\xampp2\mysql\bin"
set "BACKUP_DIR=C:\Users\aeolian\YandexDisk\crm_backups"
set "DB_NAME=santeks_crm"

:: Формируем имя файла с текущей датой (Формат: YYYY-MM-DD_HH-MM)
set "cur_date=%date:~10,4%-%date:~4,2%-%date:~7,2%"
set "cur_time=%time:~0,2%-%time:~3,2%"
set "cur_time=%cur_time: =0%"
set "FILE_NAME=%DB_NAME%_backup_%cur_date%_%cur_time%.sql"

:: Запуск утилиты mysqldump (по умолчанию в XAMPP пароля у root нет)
echo Start exporting database %DB_NAME%...
"%MYSQL_BIN%\mysqldump.exe" --user=root --host=localhost %DB_NAME% > "%BACKUP_DIR%\%FILE_NAME%"

echo Backup finished successfully: %FILE_NAME%
