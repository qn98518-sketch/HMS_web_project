# API & GHÉP LUỒNG LỊCH HẸN — HMS (BẢN CHUẨN CHO TEAM)
**Chủ trì soạn thảo:**  Receptionist (Trung)  
**Đối tượng thực hiện:** Thành viên B (Doctor) & Thành viên C (Patient)  
**Ngày cập nhật:** 15/11/2026  

---

## 1. Nguyên tắc cốt lõi (Bắt buộc cả 3 phải tuân thủ)

### Hai trạng thái độc lập trong bảng `appointments`:
* **`doctor_response`:** `pending` | `accepted` | `rejected`  
  *(Chỉ Bác sĩ sở hữu lịch mới có quyền cập nhật trường này).*
* **`appointment_status`:** `pending` | `confirmed` | `rejected` | `completed` | `cancelled` | `no_show`  
  *(Lễ tân là người quyết định trạng thái duyệt cuối cùng).*

> **LƯU Ý:**  
> Bác sĩ bấm `accepted` **KHÔNG** làm lịch chuyển sang `confirmed` (lịch vẫn giữ `pending` để chờ Lễ tân duyệt cuối).  
> Bác sĩ bấm `rejected` **KHÔNG** làm lịch chuyển sang `rejected` (vẫn giữ slot cho đến khi Lễ tân quyết định Từ chối hoặc Duyệt ngoại lệ).

---

## 2. Luồng chuẩn kết nối giữa 3 thành viên

```text
[Bệnh nhân (Bạn C) đặt online]  HOẶC  [Lễ tân (Bạn A) tạo tại quầy]
                         │
                         ▼
        appointment_status = 'pending'
        doctor_response    = 'pending'
                         │
                         ▼
             [Bác sĩ (Bạn B) phản hồi]
             ├── Accepted ──┐
             └── Rejected ──┤ (Bắt buộc kèm doctor_reject_reason)
                            │
                            ▼
                [Lễ tân (Bạn A) duyệt cuối]
                ├── Duyệt chuẩn: Bác sĩ Accepted -> confirmed
                ├── Duyệt ngoại lệ: Bác sĩ Rejected -> confirmed (kèm review_note)
                └── Từ chối: Lễ tân reject -> rejected (giải phóng slot)
                            │
            ┌───────────────┴───────────────┐
            ▼                               ▼
 [Bác sĩ khám xong]               [Bệnh nhân không đến]
  Chuyển completed                 Lễ tân chuyển no_show
```

---

## 3. Đặc tả API cho  B (Doctor) — Thư mục `doctor_dashboard/`

Bác sĩ chỉ thao tác trên các lịch hẹn có `doctor_id` thuộc tài khoản của mình.

### 3.1. API Lấy danh sách lịch hẹn của tôi
* **Method:** `GET`
* **URL:** `appointments_feature/appointments_api.php?action=list`
* **Query SQL:**
  ```sql
  SELECT a.*, pu.full_name AS patient_name, pu.phone_number AS patient_phone
  FROM appointments a
  JOIN patients p ON p.patient_id = a.patient_id
  JOIN users pu ON pu.user_id = p.user_id
  WHERE a.doctor_id = :my_doctor_id
  ORDER BY a.appointment_datetime ASC;
  ```

### 3.2. API Phản hồi yêu cầu lịch hẹn (Accept / Reject)
* **Method:** `POST`
* **URL:** `appointments_feature/appointments_api.php`
* **Tham số Body:**
  * `action`: `"respond"`
  * `id`: `<appointment_id>`
  * `decision`: `"accepted"` hoặc `"rejected"`
  * `reject_reason`: `"<Lý do từ chối>"` *(Bắt buộc nếu decision = 'rejected')*
* **Xử lý Backend:**
  ```sql
  -- Kiểm tra lịch phải ở trạng thái pending và đúng doctor_id
  UPDATE appointments
  SET doctor_response = :decision,
      doctor_reject_reason = :reject_reason,
      doctor_responded_at = NOW()
  WHERE appointment_id = :id AND doctor_id = :my_doctor_id AND appointment_status = 'pending';
  ```
  *(Tuyệt đối không sửa `appointment_status`).*

### 3.3. API Hoàn tất ca khám (Complete)
* **Method:** `POST`
* **URL:** `appointments_feature/appointments_api.php`
* **Tham số Body:**
  * `action`: `"complete"`
  * `id`: `<appointment_id>`
* **Điều kiện:** `appointment_status = 'confirmed'` và `NOW() >= appointment_datetime`. Chuyển `appointment_status = 'completed'`.

---

## 4. Đặc tả API cho Bạn C (Patient) — Thư mục `patient_dashboard/`

Bệnh nhân chỉ thao tác với dữ liệu gắn đúng `patient_id` của bản thân (tra từ session `user_id`).

### 4.1. API Kiểm tra giờ trống của bác sĩ (Availability)
* **Method:** `GET`
* **URL:** `appointments_feature/appointments_api.php?action=availability&doctor_id=...&date=YYYY-MM-DD`
* **Query SQL:**
  ```sql
  SELECT appointment_datetime
  FROM appointments
  WHERE doctor_id = :doctor_id AND DATE(appointment_datetime) = :date
    AND appointment_status IN ('pending', 'confirmed');
  ```
  *(Frontend hiển thị các khung giờ này là "Đã bị chiếm", không cho bệnh nhân chọn trùng).*

### 4.2. API Đặt lịch khám online
* **Method:** `POST`
* **URL:** `appointments_feature/appointments_api.php`
* **Tham số Body:**
  * `action`: `"create"`
  * `doctor_id`: `<doctor_id>`
  * `appointment_datetime`: `"YYYY-MM-DD HH:mm:ss"` *(Phải là thời gian tương lai)*
  * `visit_reason`: `"<Lý do đi khám>"`
* **Khởi tạo bắt buộc:**
  * `appointment_status`: `'pending'`
  * `doctor_response`: `'pending'`

### 4.3. API Hủy lịch hẹn cá nhân
* **Method:** `POST`
* **URL:** `appointments_feature/appointments_api.php`
* **Tham số Body:**
  * `action`: `"cancel"`
  * `id`: `<appointment_id>`
* **Điều kiện:** Lịch phải có `patient_id = :my_patient_id`, trạng thái là `pending` hoặc `confirmed`, và **`NOW() < appointment_datetime`** (chưa qua giờ hẹn).
* **Kết quả:** Đổi `appointment_status = 'cancelled'`. (Không DELETE dòng trong DB).
