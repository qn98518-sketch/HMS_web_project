# TÀI LIỆU LUỒNG DỮ LIỆU PHÂN HỆ LỄ TÂN (RECEPTIONIST MODULE)
**Dự án:** Hệ Thống Quản Lý Bệnh Viện (Hospital Management System - HMS)  
**Thành viên đảm nhiệm:** Nguyễn Đức Trung — Lễ tân (Receptionist)  
**Phạm vi:** Tiếp nhận bệnh nhân, Điều phối lịch hẹn (2 trạng thái), Quản lý hóa đơn & Thu tiền quầy.

---

## 1. TỔNG QUAN KIẾN TRÚC & PHÂN QUYỀN CỦA LỄ TÂN

Phân hệ Lễ tân đóng vai trò là "Cửa ngõ tiếp nhận và điều phối hành chính" của bệnh viện, giao tiếp dữ liệu với Bệnh nhân (Patient), Bác sĩ (Doctor) và Quản trị viên (Admin).

```
   ┌─────────────────────────────────────────────────────────────┐
   │                     LỄ TÂN (RECEPTIONIST)                   │
   │  [1. Tiếp nhận BN]     [2. Duyệt lịch hẹn]    [3. Thu tiền] │
   └──────────┬───────────────────┬───────────────────┬──────────┘
              │                   │                   │
              ▼                   ▼                   ▼
   ┌────────────────────┐ ┌────────────────┐ ┌────────────────┐
   │  BỆNH NHÂN         │ │  BÁC SĨ        │ │  HÓA ĐƠN       │
   │  users + patients  │ │  appointments  │ │  bills         │
   └────────────────────┘ └────────────────┘ └────────────────┘
```

### 🔒 Nguyên Tắc Bảo Mật & Ranh Giới Dữ Liệu
1. **Không xem dữ liệu lâm sàng:** Lễ tân tuyệt đối **không** đọc hoặc sửa bệnh án (`medical_history`), đơn thuốc, kết quả xét nghiệm hoặc chẩn đoán của bác sĩ.
2. **Giới hạn sửa hành chính:** Chỉ được sửa Số điện thoại (`users.phone_number` tối đa 10 ký tự) và Địa chỉ (`patients.address`). Tuyệt đối không can thiệp vào mật khẩu, role, trạng thái tài khoản.
3. **An toàn tài chính:** Hóa đơn đã thu tiền (`paid_amount > 0`) không bao giờ được phép xóa/hủy; không sửa lùi số tiền để hoàn tiền tùy tiện.
4. **Phân quyền độc lập:** Hoạt động độc lập trong thư mục `receptionist_dashboard/`, không làm ảnh hưởng mã nguồn của các thành viên khác.

---

## 2. CHI TIẾT CÁC LUỒNG DỮ LIỆU (DATA FLOWS)

---

### LUỒNG 1: TIẾP NHẬN BỆNH NHÂN TẠI QUẦY & CẬP NHẬT HÀNH CHÍNH
*(Thư mục mã nguồn: `receptionist_dashboard/patients_feature/`)*

#### Mục đích:
Tiếp nhận người bệnh đến khám trực tiếp tại quầy, tạo hồ sơ mới an toàn trong một giao dịch cơ sở dữ liệu (Transaction) và tra cứu/chỉnh sửa thông tin liên lạc.

#### Sơ đồ tuần tự (Sequence Diagram):
```
[Lễ tân]                      [Frontend UI]                   [Backend API]                   [MySQL Database]
   │                                │                              │                                 │
   │─── 1. Nhập họ tên, SĐT, NS ───>│                              │                                 │
   │    giới tính, địa chỉ          │── 2. POST action=create ────>│                                 │
   │                                │                              │── 3. START TRANSACTION ────────>│
   │                                │                              │── 4. Sinh username duy nhất ───>│
   │                                │                              │── 5. INSERT INTO users ────────>│ (role='patient')
   │                                │                              │── 6. INSERT INTO patients ─────>│ (user_id vừa tạo)
   │                                │                              │── 7. COMMIT TRANSACTION ───────>│
   │                                │<── 8. Trả về Patient ID ─────│                                 │
   │<── 9. Hiển thị vào danh sách ──│                              │                                 │
```

#### Quy tắc xử lý dữ liệu:
- **Tạo mới:** Backend tự động mở Transaction:
  1. Sinh `username` tự động dạng `patient_XXXXX` (duy nhất).
  2. Băm mật khẩu ngẫu nhiên an toàn (`password_hash`), cố định gán `role = 'patient'`.
  3. Tạo bản ghi trong bảng `users` (lưu tên, SĐT, ngày sinh, giới tính).
  4. Tạo bản ghi trong bảng `patients` liên kết với `user_id` vừa tạo (lưu địa chỉ).
  5. Cam kết giao dịch (`COMMIT`). Nếu một trong hai bảng lỗi thì hủy toàn bộ (`ROLLBACK`).
- **Tra cứu:** Tìm kiếm nhanh theo Họ tên hoặc Số điện thoại. Chỉ trả về các cột hành chính: Mã BN, Họ tên, Username, SĐT, Ngày sinh, Giới tính, Địa chỉ.
- **Sửa hành chính:** Lễ tân chỉ được cập nhật `users.phone_number` (chuỗi số tối đa 10 chữ số) và `patients.address`.

---

### LUỒNG 2: ĐIỀU PHỐI & DUYỆT LỊCH HẸN 2 TRẠNG THÁI (TWO-STATE APPROVAL)
*(Thư mục mã nguồn: `receptionist_dashboard/appointments_feature/`)*

Lịch hẹn áp dụng mô hình phối hợp **2 trạng thái độc lập** giữa Bác sĩ và Lễ tân:
- Trạng thái phản hồi của Bác sĩ (`doctor_response`): `pending` ➔ `accepted` hoặc `rejected`.
- Trạng thái tổng của Lịch hẹn (`appointment_status`): `pending` ➔ `confirmed` / `rejected` / `cancelled` / `no_show`.

#### Sơ đồ luồng trạng thái (State Diagram):
```
                       [Bệnh nhân đặt / Lễ tân tạo tại quầy]
                                         │
                                         ▼
                         ┌───────────────────────────────┐
                         │ appointment_status = pending  │
                         │ doctor_response    = pending  │
                         └───────────────┬───────────────┘
                                         │
                       ┌─────────────────┴─────────────────┐
                       ▼                                   ▼
             (Bác sĩ chọn Accept)                (Bác sĩ chọn Reject)
                       │                                   │
                       ▼                                   ▼
        ┌───────────────────────────────┐   ┌───────────────────────────────┐
        │ appointment_status = pending  │   │ appointment_status = pending  │
        │ doctor_response    = accepted │   │ doctor_response    = rejected │
        └──────────────┬────────────────┘   └──────────────┬────────────────┘
                       │                                   │
               (Lễ tân bấm Approve)              (Lễ tân bấm Override Approve
                       │                          kèm lý do điều phối quầy)
                       ▼                                   │
        ┌───────────────────────────────┐                  │
        │ appointment_status = confirmed│◄─────────────────┘
        │ doctor_response    = accepted │
        └───────────────────────────────┘
```

#### Các bước xử lý dữ liệu:
1. **Đặt lịch tại quầy:** Lễ tân chọn Bệnh nhân ➔ Chọn Bác sĩ ➔ Chọn ngày ➔ Hệ thống kiểm tra giờ trống (`action=availability`) ➔ Lưu lịch hẹn với trạng thái ban đầu luôn là `Pending` (không tự duyệt).
2. **Bác sĩ xem và phản hồi:** Bác sĩ vào xem lý do khám và chọn `Accept` hoặc `Reject` (kèm lý do bận/chuyên môn). Thao tác này **chỉ** đổi cột `doctor_response`, lịch hẹn vẫn ở trạng thái chờ duyệt của quầy.
3. **Lễ tân chốt duyệt (Approve):**
   - Chỉ duyệt khi Bác sĩ đã `accepted`.
   - Lễ tân bấm **Approve**: Hệ thống khóa dòng (`FOR UPDATE`), đổi `appointment_status = 'confirmed'`, lưu `reviewed_by` là ID lễ tân đang đăng nhập, lưu `reviewed_at = NOW()`.
4. **Xử lý ngoại lệ (Override Approve):** Nếu Bác sĩ `rejected` nhưng bệnh nhân là trường hợp đặc biệt đã được thỏa thuận tại quầy, Lễ tân được quyền kích hoạt nút duyệt ngoại lệ, bắt buộc phải nhập ghi chú điều phối (`review_note`).
5. **Đổi giờ (Reschedule):** Nếu lễ tân sửa ngày/giờ khám của lịch hẹn, hệ thống tự động reset `doctor_response = 'pending'` và xóa kết quả duyệt cũ để Bác sĩ xác nhận lại giờ mới.
6. **Điểm danh vắng mặt (Mark No-show):** Chỉ cho phép bấm khi lịch đang `confirmed` và thời gian hẹn **đã trôi qua trong quá khứ**.

---

### LUỒNG 3: QUẢN LÝ HÓA ĐƠN & THU TIỀN TẠI QUẦY (BILLS & PAYMENTS)
*(Thư mục mã nguồn: `receptionist_dashboard/bills_feature/`)*

#### Mục đích:
Xuất hóa đơn dịch vụ, ghi nhận các đợt thu tiền mặt/thẻ/chuyển khoản của bệnh nhân và quản lý công nợ chính xác tuyệt đối.

#### Quy trình thu tiền:
```
[Tạo hóa đơn: Total = 500,000 | Paid = 0]
       │  Trạng thái: "Unpaid"
       ▼
[Thu tiền đợt 1: Nhận 200,000 qua Thẻ]
       │  Paid = 200,000 | Balance = 300,000
       ▼  Trạng thái tự đổi: "Partially Paid"
[Thu tiền đợt 2: Nhận nốt 300,000 Tiền mặt]
       │  Paid = 500,000 | Balance = 0
       ▼  Trạng thái tự đổi: "Paid"
[Hóa đơn tất toán xong - Khóa chức năng thu tiền]
```

#### Quy tắc dữ liệu & Tính toàn vẹn:
- **Độ chính xác tiền tệ:** Sử dụng số nguyên cent (nhân 100) trong toàn bộ quá trình tính toán ở server để triệt tiêu sai số làm tròn số thực.
- **Tự động tính trạng thái:**
  - Nếu `paid_amount = 0` ➔ `Unpaid`
  - Nếu `0 < paid_amount < total_amount` ➔ `Partially Paid`
  - Nếu `paid_amount == total_amount` ➔ `Paid`
- **Kiểm soát dư nợ:** Số tiền thu mỗi lần không được vượt quá số dư còn thiếu (`Balance = Total - Paid`).
- **Khóa lạc quan (Optimistic Locking):** Dùng chuỗi băm `revision` (SHA-256) trên từng hóa đơn để chống xung đột khi 2 người cùng thao tác một lúc.
- **Chặn hủy sai quy định:** Hóa đơn chỉ có thể bấm **Cancel** khi chưa thu bất kỳ đồng nào (`paid_amount == 0`). Nếu đã thu tiền, nút Cancel bị ẩn hoàn toàn để bảo vệ quỹ.

---

### LUỒNG 4: QUẢN LÝ TÀI KHOẢN CÁ NHÂN LỄ TÂN (MY ACCOUNT)
*(Thư mục mã nguồn: `receptionist_dashboard/account_feature/`)*

#### Mục đích:
Cho phép nhân viên lễ tân tự quản lý hồ sơ cá nhân, đổi ảnh đại diện (avatar) và đổi mật khẩu an toàn, chạy hoàn toàn độc lập trong giao diện của Lễ tân mà không phụ thuộc trang Admin.

#### Các bước thực hiện:
1. **Xem thông tin:** Gọi API lấy thông tin người dùng từ phiên đăng nhập (`users`: họ tên, email, SĐT, avatar).
2. **Cập nhật hồ sơ:** Cho phép cập nhật họ tên, email, số điện thoại của tài khoản Lễ tân.
3. **Đổi ảnh đại diện (Avatar):** Tải lên file ảnh (JPG, PNG, WEBP, GIF tối đa 2MB) hoặc xóa ảnh đại diện.
4. **Đổi mật khẩu:** Yêu cầu nhập mật khẩu hiện tại, kiểm tra khớp mật khẩu mới (tối thiểu 6 ký tự) và băm an toàn trên server.
5. **Điều hướng an toàn:** Nếu truy cập nhầm trang của Admin, hệ thống tự động phát hiện role `receptionist` và chuyển hướng về giao diện Lễ tân.

---

## 3. BẢNG TỔNG HỢP CÁC BẢNG DỮ LIỆU LIÊN QUAN

| Tên bảng trong DB | Lễ tân Đọc (SELECT) | Lễ tân Ghi (INSERT/UPDATE) | Ghi chú bảo mật |
| :--- | :--- | :--- | :--- |
| `users` | Họ tên, SĐT, giới tính, ngày sinh, username | Tạo user bệnh nhân mới; Cập nhật `phone_number` | Không sửa `password`, `role`, `user_status` |
| `patients` | `patient_id`, `address`, `user_id` | Tạo mới bản ghi khi đón tiếp; Cập nhật `address` | **Tuyệt đối không đụng vào `medical_history`** |
| `appointments` | Toàn bộ thông tin lịch hẹn của quầy | Tạo lịch hẹn; Duyệt/Hủy lịch; Cập nhật giờ khám | Cập nhật `reviewed_by` từ session đang đăng nhập |
| `doctors` | Danh sách bác sĩ, chuyên khoa | Không ghi | Chỉ đọc thông tin để bệnh nhân chọn bác sĩ |
| `bills` | Danh sách hóa đơn, công nợ | Tạo hóa đơn mới; Cập nhật đợt thu tiền (`pay`) | Không xóa hóa đơn đã thu tiền |

---

## 4. KẾT LUẬN & ĐIỂM SẴN SÀNG TÍCH HỢP CHO NHÓM

- Module  chạy hoàn toàn độc lập trong thư mục `receptionist_dashboard/`, không can thiệp hay làm ảnh hưởng đến mã nguồn của Admin hay Bác sĩ.
- Luồng dữ liệu tuân thủ chuẩn RESTful API, sử dụng dữ liệu JSON, có cơ chế Transaction và mã hóa mật khẩu an toàn.
- Đã có tài liệu hợp đồng tích hợp API chi tiết tại `docs/API_CONTRACT_FOR_TEAM.md`.
