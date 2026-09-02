# 1C historical fixtures

Unmodified historical exports copied from the local autohimiki.kz server snapshot;
these are not claimed to be the current production FTP file.

| Fixture | Original file under C:/Projects/autohimiki-server-copy/storage/app/public/imports | SHA-256 |
|---|---|---|
| warehouse.xlsx | import_20260605_232735_6a23151735edd.xlsx | 53b1a43960714155a23d3618ffdf0ec0d1f0c8d4e91ca2941103a127443eaa1b |
| flat.xlsx | import_20260605_234517_6a23193d1d0ac.xlsx | 2e847ea975c8edea7ff3e2c55a276cfc8852e07e2124209b21a390a9fd5dbd9f |

Both contain the same 263 products, including 10 blank prices. All product codes
are text. The warehouse variant has two header rows, a warehouse summary, and a
final total. The flat variant has one header row and a final total. Tests also
generate synthetic XLSX edge cases using the project's existing OpenSpout library.
