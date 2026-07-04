-- migration-21: link evento + comune sulla richiesta di booking.
-- NB: auto-applicata dal codice al primo uso (ensure_request_extras in api/booking-request.php);
-- questo file esiste come riferimento/allineamento manuale.
ALTER TABLE booking_requests
  ADD COLUMN event_link VARCHAR(255) DEFAULT NULL AFTER message,
  ADD COLUMN comune VARCHAR(120) DEFAULT NULL AFTER event_link;
