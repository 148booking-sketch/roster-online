-- Migrazione 10: promuove 148booking@gmail.com (oggi account promoter) ad admin.
-- Usato anche come "mittente" per i profili artista Verificati gestiti direttamente da 148 Booking.
UPDATE users SET role = 'admin' WHERE email = '148booking@gmail.com';
