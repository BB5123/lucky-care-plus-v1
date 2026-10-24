# HealthRecord

Health profiles, conditions, allergies, measurements, and medication plans and records.

Also owns the PDPA data export (`DataExport`, `DataExportService`, the `BuildDataExport`
queued job and `DataExportBuilder`). The export JSON lives on the private object store;
the database row carries only lifecycle metadata and an encrypted opaque key.