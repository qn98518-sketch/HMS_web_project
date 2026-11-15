-- =====================================================================
-- 01 - Cột is_deleted cho bảng users (ẩn tài khoản thay vì xóa cứng)
-- Chạy MỘT LẦN trong tab SQL của phpMyAdmin (database hospital_management_system).
-- Hãy sao lưu DB trước. PHẢI chạy trước khi mở lại trang admin / đăng nhập,
-- vì auth_guard.php và login_back_end.php đã đọc cột này.
--
-- Bảng appointments giữ NGUYÊN như schema hiện tại, không cần sửa gì thêm.
-- Nếu trước đây bạn đã chạy 02_appointments_cancel_history.sql thì các cột
-- cancel_reason / cancelled_by / cancelled_at và bảng appointment_history
-- chỉ còn thừa (module mới không dùng), để nguyên hay xóa đều được.
-- =====================================================================

ALTER TABLE users
  ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0;
