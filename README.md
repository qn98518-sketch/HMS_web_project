# Hospital Management System (HMS) — Receptionist Module

Phân hệ **Lễ tân (Receptionist)** thuộc Dự án Hệ thống Quản lý Bệnh viện (HMS).  
Nhánh phát triển: **`Trung_receptionist_module`**  
Thành viên phụ trách: **Nguyễn Đức Trung — Lễ tân (Receptionist)**

---

## 1. Tổng quan Phân hệ Lễ tân

Phân hệ Lễ tân là "Cửa ngõ tiếp nhận và điều phối hành chính" của bệnh viện, đảm nhận 3 mảng chức năng cốt lõi:
1. **Tiếp nhận bệnh nhân tại quầy (Patients Reception):** Đón tiếp bệnh nhân vãng lai, tạo hồ sơ mới, tra cứu hành chính và chỉnh sửa thông tin liên lạc.
2. **Điều phối & Duyệt lịch hẹn (Appointments Desk):** Quản lý toàn bộ lịch hẹn quầy, áp dụng mô hình duyệt 2 trạng thái (*Doctor Response* vs *Appointment Status*).
3. **Quản lý hóa đơn & Thu tiền (Bills & Payments):** Lập hóa đơn dịch vụ, ghi nhận các đợt thanh toán (Tiền mặt, Thẻ, Chuyển khoản) và quản lý công nợ chính xác.

---

## 2. Cấu trúc Thư mục

```
Hospital_Management_System/
├── login_feature/                          # Luồng xác thực đăng nhập chung
│   ├── index.html                          # Giao diện đăng nhập
│   └── login_back_end.php                  # Xử lý đăng nhập và điều hướng theo role
├── receptionist_dashboard/                 # ⭐ TOÀN BỘ PHÂN HỆ LỄ TÂN (BẠN A)
│   ├── receptionist_dashboard.html         # Trang chủ điều khiển của Lễ tân (Dashboard)
│   ├── js/
│   │   ├── layout.js                       # Render Sidebar, Topbar và Sprite Icon
│   │   └── usermenu.js                     # Menu thông tin tài khoản & đăng xuất của Lễ tân
│   ├── php/
│   │   ├── auth_guard.php                  # Bảo vệ API: bắt buộc role 'receptionist'
│   │   └── check_auth.php                  # Kiểm tra phiên làm việc đăng nhập
│   ├── patients_feature/                   # Module Tiếp nhận bệnh nhân tại quầy
│   │   ├── index.html
│   │   ├── patients.js
│   │   ├── patients.css
│   │   └── patients_api.php
│   ├── appointments_feature/               # Module Điều phối & Duyệt lịch hẹn quầy
│   │   ├── index.html
│   │   ├── appointments.js
│   │   ├── appointments.css
│   │   └── appointments_api.php
│   └── bills_feature/                      # Module Hóa đơn & Thu tiền tại quầy
│       ├── index.html
│       ├── bills.js
│       ├── bills_management.css
│       ├── bills_service.php
│       └── bills_api.php
├── admin_form/                             # Phân hệ Quản trị viên (Admin - Quân/gốc)
├── database_export/                        # Bản sao lưu cơ sở dữ liệu mẫu
└── docs/                                   # Tài liệu dự án & Hợp đồng API
    ├── API_FOR_TEAM.md                     # API thống nhất của team
    └── LUONG_DU_LIEU_RECEPTIONIST.md       # Luồng dữ liệu chi tiết 
```

---

## 3. Các Chức Năng Chi Tiết Của Lễ Tân

### 3.1. Tiếp nhận bệnh nhân tại quầy (`patients_feature`)
- **Đăng ký tại quầy:** Nhập họ tên, ngày sinh, giới tính, SĐT, địa chỉ. Hệ thống tự động mở Database Transaction tạo đồng thời `users` (gán cứng role `patient`, mật khẩu ngẫu nhiên băm an toàn, sinh username duy nhất dạng `patient_XXXXX`) và `patients`.
- **Tra cứu hành chính:** Tìm kiếm nhanh theo Họ tên hoặc SĐT. Chỉ trả về thông tin hành chính công khai.
- **Sửa hành chính:** Chỉ được phép sửa SĐT (`users.phone_number` tối đa 10 số theo schema) và địa chỉ (`patients.address`).
- **Ranh giới an toàn:** Tuyệt đối không cho phép Lễ tân nhập hay đọc tiền sử bệnh (`medical_history`), không sửa mật khẩu, role hoặc trạng thái tài khoản bệnh nhân.

### 3.2. Điều phối & Duyệt lịch hẹn (`appointments_feature`)
- **Mô hình 2 trạng thái độc lập:**
  - Trạng thái Bác sĩ (`doctor_response`): `pending` ➔ `accepted` hoặc `rejected`.
  - Trạng thái Lịch hẹn (`appointment_status`): `pending` ➔ `confirmed` / `rejected` / `cancelled` / `no_show`.
- **Bàn duyệt lịch (Approval Desk):**
  - **Approve:** Lễ tân chỉ duyệt chuyển sang `Confirmed` khi Bác sĩ đã phản hồi `Accepted`. Lưu người duyệt (`reviewed_by`) từ session và giờ duyệt (`reviewed_at`).
  - **Override Approve (Ngoại lệ):** Khi Bác sĩ từ chối (`rejected`) nhưng có thỏa thuận tiếp nhận đặc biệt tại quầy, Lễ tân kích hoạt duyệt ngoại lệ và bắt buộc phải nhập ghi chú điều phối (`review_note`).
  - **Reject:** Từ chối lịch hẹn và lưu lý do.
  - **Mark No-show:** Đánh dấu bệnh nhân vắng mặt, chỉ áp dụng cho lịch đang `Confirmed` và thời gian hẹn đã qua trong quá khứ.
  - **Reschedule:** Khi đổi ngày/giờ khám, hệ thống tự động reset `doctor_response = 'pending'` và xóa kết quả duyệt cũ để Bác sĩ xác nhận lại giờ mới.
- **Ranh giới an toàn:** Lễ tân không đọc bất kỳ dữ liệu chẩn đoán hay phác đồ điều trị nào của Bác sĩ.

### 3.3. Hóa đơn & Thu tiền (`bills_feature`)
- **Lập hóa đơn quầy:** Chọn bệnh nhân, gắn với lịch hẹn tương ứng, nhập tổng tiền (`total_amount`), số tiền trả trước (`paid_amount`) và phương thức ban đầu.
- **Tính toán tài chính chính xác:** Toàn bộ tính toán công nợ trên server đều xử lý bằng số nguyên cent (nhân 100) để triệt tiêu sai số làm tròn số thực.
- **Thu tiền nhiều đợt (Record Payment):** Ghi nhận tiền thu bằng Tiền mặt (*Cash*), Thẻ (*Card*), hoặc Chuyển khoản (*Bank Transfer*). Cập nhật số tiền đã trả và tự động tính lại trạng thái:
  - `Unpaid`: Chưa thanh toán (`paid = 0`).
  - `Partially Paid`: Đã thanh toán 1 phần (`0 < paid < total`).
  - `Paid`: Đã thanh toán đủ (`paid == total`). Nút thu tiền tự động ẩn.
- **Quy tắc an toàn tài chính:**
  - Không cho phép thu vượt quá số dư còn nợ (`Balance = Total - Paid`).
  - Không cho phép hủy hóa đơn đã thu tiền (`paid > 0`). Chỉ được hủy hóa đơn chưa thu tiền (`paid = 0`).
  - Sử dụng khóa lạc quan (*Optimistic Locking*) qua chuỗi băm `revision` (SHA-256) để chống xung đột ghi đè.

---

## 4. Tài Khoản Thử Nghiệm (Test Accounts)

| Vai trò | Tên đăng nhập | Mật khẩu | Trang bắt đầu |
| :--- | :--- | :--- | :--- |
| **Lễ tân (Receptionist)** | `trung1` | `123` | `http://localhost/asm/receptionist_dashboard/receptionist_dashboard.html` |
| **Quản trị viên (Admin)** | `admin` | `admin` | `http://localhost/asm/admin_form/homepage.html` |

---

## 5. Hướng Dẫn Cài Đặt & Chạy Cục Bộ

1. **Khởi động XAMPP:**
   - Mở XAMPP Control Panel, bật **Apache** và **MySQL**.
2. **Cơ sở dữ liệu:**
   - Mở phpMyAdmin: `http://localhost/phpmyadmin/`.
   - Tạo cơ sở dữ liệu `hospital_management_system`.
   - Import file: `database_export/hospital_management_system_20261115_143552/hospital_management_system.sql`.
3. **Mã nguồn:**
   - Đặt thư mục dự án tại `C:\xampp\htdocs\asm` (hoặc `C:\xampp\htdocs\Hospital_Management_System`).
4. **Truy cập:**
   - Đăng nhập: [http://localhost/asm/login_feature/index.html](http://localhost/asm/login_feature/index.html)
   - Truy cập thẳng Dashboard Lễ tân: [http://localhost/asm/receptionist_dashboard/receptionist_dashboard.html](http://localhost/asm/receptionist_dashboard/receptionist_dashboard.html)

---

## 6. Tài Liệu Kỹ Thuật Tham Khảo

- **[docs/LUONG_DU_LIEU_RECEPTIONIST.md](docs/LUONG_DU_LIEU_RECEPTIONIST.md):** Bản giải thích chi tiết toàn bộ luồng dữ liệu (Data Flow) bằng tiếng Việt kèm sơ đồ tuần tự và sơ đồ trạng thái.
- **[docs/API_FOR_TEAM.md](docs/API_FOR_TEAM.md):** Hợp đồng tích hợp API chi tiết giữa 3 phân hệ: Lễ tân (Bạn A), Bác sĩ (Bạn B) và Bệnh nhân (Bạn C).
