DATABASE EXPORT — hospital_management_system

hospital_management_system.sql
  Export trực tiếp bằng mysqldump từ MySQL/MariaDB đang chạy trong XAMPP.
  Chứa cấu trúc database/bảng, toàn bộ dữ liệu, chỉ mục, khóa ngoại,
  ràng buộc CHECK và routine/trigger/event nếu database có các đối tượng này.
  Các bảng InnoDB được export trong một snapshot nhất quán (single transaction).

manifest.json
  Phiên bản server, thời điểm xác minh, danh sách bảng, số dòng dữ liệu,
  số lượng đối tượng, kích thước và SHA-256 của file SQL.
  Số dòng trong bản export đã được đối chiếu với database trực tiếp.

Khôi phục
  Trong phpMyAdmin, chọn Import và chọn hospital_management_system.sql.
  File đã chứa CREATE DATABASE và USE hospital_management_system.
  Import vào database cùng tên sẽ tạo lại các bảng và thay thế dữ liệu hiện có.
  Chưa thực hiện import/restore trên database hiện tại.

Chia sẻ với team
  Thư mục database_export được phép đưa vào Git. Commit/push thư mục này và
  .gitignore để các thành viên có thể nhận file SQL qua clone/pull.
  File có dữ liệu cá nhân và mật khẩu tài khoản đã băm; chỉ chia sẻ trong repo nội bộ.
  Đây là snapshot, không tự đồng bộ MySQL giữa các máy.

Phạm vi
  File ảnh avatar nằm trong uploads/avatars không thuộc database;
  cột users.avatar chỉ chứa tên file.
  Không bao gồm tài khoản/quyền quản trị MySQL server hay cấu hình XAMPP.
  Thư mục export được chặn truy cập HTTP.