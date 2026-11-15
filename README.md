# Hospital Management System

Hướng dẫn chia việc cho team: [Module Receptionist, Doctor, Patient và luồng lịch hẹn](docs/HUONG_DAN_CHIA_MODULE_THEO_ROLE.md).

```
Hospital_Management_System/
├── login_feature/                  (giữ nguyên)
│   ├── index.html
│   ├── style.css
│   ├── login_back_end.php          -> admin chuyển tới ../admin_form/homepage.html
│   └── login_pics/
└── admin_form/
    ├── homepage.html               Dashboard (trang vào sau khi đăng nhập)
    ├── css/
    │   ├── layout.css              Sidebar + header + vùng nội dung (dùng chung)
    │   └── common.css              Button, badge, table-card, modal, form (dùng chung)
    ├── js/
    │   └── layout.js               Dựng sidebar/header/icon cho mọi trang + danh sách MENU
    ├── images/logo.png
    ├── users_management_feature/
    │   ├── index.html
    │   ├── users.js
    │   └── users_management.css
    ├── products_feature/index.html     (khung trống)
    └── reports_feature/index.html      (khung trống)
```

## Chạy
Đặt folder vào `htdocs` của XAMPP rồi mở `http://localhost/Hospital_Management_System/login_feature/`.
Các trang admin cũng mở trực tiếp được bằng cách double-click `admin_form/homepage.html`.

## Thêm module mới (vd: Patients)
1. Tạo `admin_form/patients_feature/index.html` (copy từ `products_feature/index.html`,
   đổi `data-page="patients"`), cùng `patients.js` / `patients.css` nếu cần.
2. Thêm 1 dòng vào mảng `MENU` trong `admin_form/js/layout.js`.
## Appointments Management & xóa tài khoản
Chạy `sql_migrations/01_users_is_deleted.sql` trong phpMyAdmin (một lần, sau khi sao lưu DB).
- Module `admin_form/appointments_feature/` dùng đúng các cột của bảng `appointments` hiện tại.
- Xóa tài khoản: chưa có lịch hẹn thì xóa vĩnh viễn; đã có lịch hẹn (khóa ngoại chặn) thì đặt `users.is_deleted = 1`.
  Tài khoản bị ẩn không hiện ở Users / Patients / Doctors management và không đăng nhập được;
  chỉ còn hiện tên trong lịch hẹn cũ ở Appointments management.

## Bills Management
- Mở `admin_form/bills_feature/` từ sidebar hoặc Dashboard bằng tài khoản Admin.
- Dùng bảng `bills` hiện có: `bill_id`, `patient_id`, `appointment_id`, `total_amount`,
  `paid_amount`, `payment_method`, `payment_status`, `bill_date`, `due_date`, `notes`.
  Không cần thêm cột hay thay đổi schema.
- Tạo hóa đơn cho bệnh nhân đang ở role `patient`, tài khoản hoạt động/chưa bị ẩn;
  vẫn hỗ trợ hồ sơ bệnh nhân không liên kết tài khoản. Lịch hẹn là tùy chọn và phải
  thuộc đúng bệnh nhân. Hồ sơ/tài khoản đã đổi role vẫn được hiển thị trong hóa đơn cũ.
- Hóa đơn mới có thể ghi nhận khoản đã trả ban đầu. Trạng thái tự tính: chưa trả là
  `Unpaid` (kể cả hóa đơn tổng 0), trả một phần là `Partially Paid`, trả đủ là `Paid`.
- Sửa tổng tiền, hạn trả và ghi chú; không đổi bệnh nhân/lịch hẹn sau khi tạo.
  Tổng tiền không được thấp hơn số đã trả. Hạn trả không được trước ngày lập hóa đơn.
- `Record Payment` cộng khoản thu vào `paid_amount`, chặn trả vượt dư nợ. Các thao tác
  sử dụng transaction, khóa dòng và kiểm tra phiên bản dữ liệu để tránh ghi đè/thu trùng
  từ một form cũ. Tiền được xử lý bằng số nguyên đơn vị 0.01 để tránh sai số số thực.
- Chỉ hủy hóa đơn chưa thu tiền; hóa đơn hủy được giữ để tra cứu và không thể sửa/thu tiếp.
- Schema hiện chỉ lưu tổng đã thu và phương thức thu gần nhất; chưa có lịch sử từng lần
  thanh toán, hoàn tiền, chi tiết dịch vụ hay đơn vị tiền tệ. Giao diện không tự gán tiền tệ.
- Bộ lọc gồm trạng thái, phương thức, ngày lập, quá hạn và tìm kiếm; tổng tiền trên trang
  tính theo các hóa đơn đang lọc, loại trừ hóa đơn đã hủy.

## Reports (MySQL)
- Trang `admin_form/reports_feature/index.html` lấy số liệu thật từ `reports_api.php`,
  chỉ tài khoản Admin còn hiệu lực được truy cập. Mở qua localhost/XAMPP sau khi đăng nhập.
- Lịch hẹn: đếm theo `appointments.appointment_datetime`, gồm tất cả trạng thái.
- Doanh thu theo tháng hóa đơn: tổng `bills.paid_amount` nhóm theo `bills.bill_date`, bỏ
  hóa đơn `Cancelled`. Schema chưa có ngày thu tiền của từng khoản thanh toán nên đây
  không phải dòng tiền thực thu trong tháng. Thu thêm tiền sẽ cập nhật tháng lập hóa đơn cũ.
  Hóa đơn đã thu nhưng thiếu ngày lập được loại khỏi thống kê tháng và có thông báo số lượng.
- Bệnh nhân mới: đếm hồ sơ theo `patients.created_at`, không lọc role/trạng thái tài khoản
  hiện tại; giữ thống kê của hồ sơ xóa mềm. Hồ sơ đã xóa vĩnh viễn không còn trong DB để đếm.
- Đơn vị tiền giữ nguyên như Bills; backend trả số nguyên đơn vị 0.01 để tính tổng chính xác.
- Chọn năm và khoảng tháng rồi Apply; Refresh tải lại đúng khoảng đang xem. Reset về năm
  hiện tại từ tháng 1 đến tháng hiện tại theo giờ DB. Năm chọn lấy từ dữ liệu và năm hiện tại.
- Tháng không có bản ghi hiện 0; tháng tương lai để trống doanh thu/bệnh nhân mới nhưng
  vẫn hiện lịch hẹn đã đặt. Không so sánh cùng kỳ khi khoảng xem có tháng chưa hoàn tất.
- Có biểu đồ, tổng hợp, chi tiết theo tháng và CSV theo bộ lọc. Không có dữ liệu mẫu dự phòng
  nếu API lỗi.

## Avatar và Account Management
- Đã tích hợp gói `avatar_account_feature`: ảnh đại diện/menu tài khoản trên header,
  trang `admin_form/account_feature/`, và đổi/gỡ ảnh của người dùng trong Users Management.
- Migration `sql_migrations/03_user_avatar.sql` thêm `users.avatar VARCHAR(255) NULL`;
  có thể chạy lại an toàn. DB đã được sao lưu vào `.local_backups/` trước khi áp dụng.
  Thư mục sao lưu bị chặn HTTP và được loại khỏi Git.
- Tài khoản chưa có ảnh dùng avatar chữ cái đầu tên. JPG/PNG/GIF/WEBP tối đa 2 MB,
  tối đa 16 triệu pixel; ảnh được cắt vuông và mã hóa lại thành JPEG 256×256.
- Nếu Apache chưa bật GD, hệ thống dùng PHP CLI của XAMPP với `-d extension=gd`
  chỉ cho tiến trình xử lý ảnh. Không sửa `php.ini` toàn máy. Cần cho phép `proc_open`
  và có `php.exe` cùng thư mục với `php.ini`; nếu GD đã bật thì xử lý trực tiếp.
- Ảnh chỉ được đọc qua `admin_form/php/avatar.php` sau khi xác thực. Người dùng
  chỉ xem/sửa ảnh của mình; Admin có thể xem/sửa ảnh của tài khoản chưa bị ẩn.
  `uploads/avatars/.htaccess` chặn đọc trực tiếp thư mục/file ảnh.
- Account Management sửa họ tên/email/điện thoại (tối đa 10 ký tự theo schema hiện tại),
  đổi mật khẩu sau khi xác minh mật khẩu cũ, và có menu đăng xuất.
- Non-admin có thể tự khóa/xóa sau khi nhập lại mật khẩu. Giữ nguyên cơ chế `is_deleted`:
  còn lịch hẹn/hóa đơn thì ẩn tài khoản và giữ lịch sử; không có dữ liệu tham chiếu thì
  xóa hẳn và dọn ảnh. Không tự khóa/xóa Admin. Trang Account hỗ trợ xác thực theo tài khoản,
  các trang quản trị khác vẫn kiểm tra quyền Admin.
- Thay ảnh trong Users Management được lưu ngay, độc lập với nút Save của form tài khoản.

## Database dùng chung cho team
- Bản export đầy đủ nằm tại
  `database_export/hospital_management_system_20261115_143552/hospital_management_system.sql`.
- Thư mục `database_export/` được phép đưa vào Git. Cần commit và push thư mục này cùng
  `.gitignore` để các thành viên nhận được file khi clone/pull repository.
- Mỗi thành viên mở phpMyAdmin, chọn Import và chọn file SQL trên. File có sẵn lệnh
  tạo/chọn database `hospital_management_system`, cấu trúc và dữ liệu của 9 bảng.
  Import vào DB cùng tên sẽ tạo lại các bảng và thay thế dữ liệu tương ứng.
- Đây là snapshot tại thời điểm export, không tự đồng bộ MySQL giữa các máy.
  Khi thay đổi DB, cần export bản mới rồi commit/push để team cập nhật.
- File chứa dữ liệu tài khoản và mật khẩu đã băm; chỉ chia sẻ trong repository nội bộ.
  Ảnh avatar nằm ở `uploads/avatars` và không được nhúng trong file SQL.

# HMS_web_project
This project is a hospital management website which is built by a group of students who want to learn how a website acctually works. It's flaw and we'll try to improve.
