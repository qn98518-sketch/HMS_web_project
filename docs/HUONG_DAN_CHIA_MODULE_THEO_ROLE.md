# Hướng dẫn chia module theo vai trò — HMS

Tài liệu dành cho ba thành viên phụ trách Receptionist, Doctor và Patient. Mục tiêu là mỗi bạn code phần việc riêng nhưng dùng cùng DB, cùng quy tắc lịch hẹn và ghép được với Admin hiện tại.

**Phạm vi:** phần lịch hẹn mô tả chi tiết luồng, dữ liệu, quyền và điều kiện nghiệm thu. Các chức năng khác mô tả ngắn gọn. Đây là hướng dẫn triển khai; các API/dashboard cho ba vai trò bên dưới **chưa được tạo bởi tài liệu này**.

## 1. Nền tảng hiện tại và cách chia việc

### 1.1. Những phần thực sự đã có

| Thành phần | Hiện trạng |
|---|---|
| Admin | Có Users, Doctors, Patients, Appointments, Bills, Reports |
| Đăng nhập | Lưu `user_id`, `username`, `role` vào session; có nhánh Admin/Doctor/Patient. Chưa có nhánh Receptionist |
| Dashboard Doctor/Patient | Backend đăng nhập trả đường dẫn nhưng các folder dashboard chưa tồn tại |
| Hồ sơ | `users` lưu tài khoản/thông tin liên hệ; `patients`, `doctors` lưu hồ sơ liên kết qua `user_id` |
| Lịch hẹn | Admin có `list/create/update/cancel`; DB có sẵn phản hồi bác sĩ và quyết định lễ tân |
| Bệnh án, đơn thuốc, xét nghiệm | DB có các bảng nhưng chưa có module thực hiện đầy đủ |
| Hóa đơn | Có tổng tiền, đã trả, phương thức, trạng thái; chưa có bảng chi tiết dịch vụ hoặc lịch sử từng lần thu |
| Tài khoản cá nhân | Có API sửa thông tin, đổi mật khẩu, avatar, đăng xuất; đã hỗ trợ xác thực tài khoản cá nhân |
| Giờ làm việc/thông báo | Chưa có bảng ca làm, nghỉ phép, thời lượng khám, email/SMS hoặc hàng đợi thông báo |

### 1.2. Phân công đề xuất

| Thành viên | Sở hữu module | Trách nhiệm ghép luồng |
|---|---|---|
| A — Receptionist | Tiếp nhận bệnh nhân, lịch hẹn quầy, duyệt lịch, hóa đơn, thông báo | Chủ trì thống nhất API lịch hẹn và thông báo với hai bạn còn lại |
| B — Doctor | Lịch cá nhân, phản hồi lịch, hồ sơ điều trị, đơn thuốc, xét nghiệm | Thực hiện phản hồi bác sĩ và chuyển lịch sang `completed` |
| C — Patient | Đăng ký, hồ sơ cá nhân, đặt/hủy lịch, lịch sử khám, hóa đơn cá nhân | Thực hiện luồng online và kiểm tra dữ liệu chỉ thuộc bản thân |
| Người giữ phần Admin | Giữ ổn định chức năng đang chạy, duyệt thay đổi dùng chung | Phối hợp sửa quy tắc chung nếu team bổ sung kiểm tra lịch trống/ca làm |

Gợi ý folder để khớp các đường dẫn đã có trong đăng nhập:

```text
Hospital_Management_System/
├── receptionist_dashboard/
│   ├── receptionist_dashboard.html
│   ├── patients_feature/
│   ├── appointments_feature/
│   ├── bills_feature/
│   └── notifications_feature/
├── doctor_dashboard/
│   ├── doctor_dashboard.html
│   ├── appointments_feature/
│   ├── patients_feature/
│   └── treatment_feature/
├── patient_dashboard/
│   ├── patient_dashboard.html
│   ├── profile_feature/
│   ├── appointments_feature/
│   ├── history_feature/
│   └── bills_feature/
└── shared/php/                      # Đề xuất bổ sung, chưa có sẵn
    ├── session_guard.php
    ├── appointments_service.php
    └── availability_service.php
```

Mỗi feature có HTML, JS, CSS và PHP API tương tự các module Admin. Không copy nguyên API Admin rồi chỉ giấu bớt nút. Nên tách quy tắc lịch hẹn ra service dùng chung; API từng vai trò kiểm tra quyền và phạm vi dữ liệu trước khi gọi service.

## 2. Quy tắc chung bắt buộc

### 2.1. Xác thực và phân quyền

- Mọi API phải kiểm tra session và đọc lại `users.role`, `user_status`, `is_deleted`. Tài khoản không còn hoạt động hoặc bị ẩn không được tiếp tục thao tác.
- Admin API tiếp tục dùng quyền Admin. Tạo guard tương ứng cho Receptionist/Doctor/Patient; không sửa `requireAdmin()` thành cho phép tất cả vai trò.
- Doctor: tra `doctors.doctor_id` từ `users.user_id` đang đăng nhập. Patient: tra `patients.patient_id` từ tài khoản đang đăng nhập. Không lấy danh tính của bản thân từ ID do frontend gửi lên.
- Receptionist không được chọn role, sửa quyền, bật lại tài khoản bị khóa hoặc đọc hash mật khẩu của người dùng.
- Danh sách/API trả đúng các trường được phép xem. Không trả chẩn đoán rồi dùng CSS/JS để ẩn khỏi lễ tân.
- Kiểm tra quyền cả ở API danh sách, chi tiết và API ghi dữ liệu; đổi UUID trong request không được mở được dữ liệu của người khác.
- Thao tác ghi dùng POST, prepared statement và cơ chế chống CSRF thống nhất. Không thay đổi dữ liệu bằng GET.
- HTTP: chưa đăng nhập/hết quyền tài khoản `401`; sai vai trò `403`; bản ghi không thuộc phạm vi của người gọi có thể trả `404`; dữ liệu sai `400`; xung đột trạng thái/khung giờ `409`.

### 2.2. Quyền tổng quát

| Dữ liệu/thao tác | Receptionist | Doctor | Patient |
|---|---|---|---|
| Thông tin hành chính bệnh nhân | Phục vụ tiếp đón: tìm, tạo, sửa SĐT/địa chỉ | Bệnh nhân có lịch hợp lệ với mình | Chỉ của mình |
| Tiền sử bệnh `medical_history` | Không đọc/sửa trong module tiếp đón | Đọc/cập nhật bệnh nhân thuộc phạm vi điều trị | Đọc của mình nếu hiển thị; không sửa |
| Lịch hẹn | Xử lý lịch toàn quầy | Chỉ lịch có `doctor_id` của mình | Chỉ lịch có `patient_id` của mình |
| Chẩn đoán/đơn thuốc/xét nghiệm | Không truy cập | Chỉ dữ liệu điều trị do mình phụ trách | Chỉ kết quả của mình đã hoàn tất theo mục 6 |
| Hóa đơn | Tạo/thu tiền theo quyền tài chính | Không truy cập | Chỉ xem của mình |
| Tài khoản khác/quyền người dùng | Không quản trị | Không quản trị | Không quản trị |

**Giải quyết điểm dễ hiểu nhầm về Doctor:** được xem tiền sử bệnh chung của bệnh nhân mình điều trị, nhưng lịch sử chi tiết bệnh án/đơn thuốc/xét nghiệm phải giới hạn `doctor_id` của mình theo yêu cầu không xem dữ liệu bác sĩ khác. Việc bệnh nhân từng hẹn với mình không tự cấp quyền đọc mọi bệnh án của bác sĩ khác. Nếu team muốn lịch sử điều trị liên bác sĩ, phải thay đổi yêu cầu phân quyền trước.

## 3. Hợp đồng lịch hẹn dùng chung — phải đọc trước khi code

### 3.1. Đúng ID, đúng bảng

| Cột | Ý nghĩa/quy tắc |
|---|---|
| `appointment_id` | UUID lịch hẹn |
| `patient_id` | Khóa của `patients`, **không phải** `users.user_id` |
| `doctor_id` | Khóa của `doctors`, **không phải** `users.user_id` |
| `appointment_datetime` | Thời điểm hẹn; thống nhất múi giờ với DB và định dạng `YYYY-MM-DD HH:mm:ss` |
| `visit_reason` | Lý do đến khám, tối đa 255 ký tự; không nhét chẩn đoán vào đây |
| `appointment_status` | Trạng thái chung của lịch |
| `doctor_response` | Phản hồi riêng của bác sĩ |
| `doctor_reject_reason` | Lý do bác sĩ từ chối; DB yêu cầu có nội dung khi phản hồi là `rejected` |
| `doctor_responded_at` | Thời điểm bác sĩ phản hồi, lấy giờ server/DB |
| `reviewed_by` | `users.user_id` của lễ tân ra quyết định, lấy từ session |
| `reviewed_at`, `review_note` | Thời điểm và ghi chú quyết định lễ tân |
| `created_at` | Thời điểm tạo, không thay khi sửa lịch |
| `slot_key` | Cột DB tự sinh để chống trùng giờ bác sĩ; không INSERT/UPDATE thủ công |

### 3.2. Hai trạng thái độc lập

`appointment_status`: `pending`, `confirmed`, `rejected`, `cancelled`, `completed`, `no_show`.

`doctor_response`: `pending`, `accepted`, `rejected`.

**Bác sĩ đồng ý không có nghĩa lịch đã được xác nhận. Bác sĩ từ chối không tự chuyển lịch sang Rejected. Lễ tân là người quyết định duyệt cuối cùng.** Đây là hướng luồng đã được Admin UI/DB chuẩn bị, chưa có API Doctor/Receptionist thực hiện.

Luồng chuẩn phải hoàn thành khi ghép ba module:

```text
Patient / Receptionist / Admin tạo lịch
  → appointment_status=pending, doctor_response=pending
  → Doctor của lịch phản hồi accepted hoặc rejected
  → Receptionist duyệt cuối:
      đồng ý → confirmed
      không đồng ý → rejected
  → Sau buổi khám: Doctor đánh dấu completed
  → Hoặc Receptionist ghi no_show nếu bệnh nhân không đến

pending/confirmed → cancelled khi chủ thể có quyền hủy
```

Một lịch mới do lễ tân tạo cũng phải đi qua Pending, không tự Confirmed. Không để Patient/Doctor gửi lên `reviewed_by`, `reviewed_at` hoặc tự xác nhận lịch.

### 3.3. Bảng chuyển trạng thái thống nhất

Các hàng Doctor/Receptionist/Patient là **yêu cầu cần triển khai**. Hàng Admin giữ theo chức năng đang có.

| Thao tác | Người được làm | Điều kiện | Kết quả |
|---|---|---|---|
| Tạo lịch | Admin, Receptionist, Patient của chính lịch | Đúng hồ sơ, hợp lệ, thời gian tương lai, không trùng | `pending` + phản hồi `pending` |
| Đồng ý nhận lịch | Doctor được chỉ định | Lịch `pending`, chưa qua giờ hẹn, phản hồi đang `pending` | Chỉ đổi phản hồi sang `accepted`, lưu thời điểm |
| Từ chối nhận lịch | Doctor được chỉ định | Như trên; phải có lý do | Phản hồi `rejected`, lịch vẫn `pending` |
| Xác nhận bình thường | Receptionist | Lịch `pending`, còn tương lai, bác sĩ đã `accepted`, bác sĩ/bệnh nhân vẫn đủ điều kiện đặt lịch | `confirmed`, lưu người/giờ duyệt |
| Xác nhận ngoại lệ | Receptionist | Lịch `pending`, còn tương lai, bác sĩ/bệnh nhân hợp lệ, bác sĩ đã `rejected`, có thao tác xác nhận ngoại lệ và ghi chú bắt buộc | `confirmed`; giữ nguyên phản hồi/lý do từ chối của bác sĩ |
| Từ chối lịch | Receptionist | Lịch `pending`; ghi lý do quyết định | `rejected`, lưu người/giờ/ghi chú duyệt |
| Sửa bác sĩ/giờ/lý do | Admin, Receptionist | Chỉ `pending` hoặc `confirmed`; xem quy tắc reset bên dưới | Giữ hoặc reset theo nội dung sửa |
| Hủy | Admin, Receptionist | Chỉ `pending` hoặc `confirmed` | `cancelled`, giữ bản ghi |
| Hủy lịch cá nhân | Patient của lịch | `pending/confirmed` và DB `NOW()` nhỏ hơn giờ hẹn | `cancelled`, giữ bản ghi |
| Hoàn tất khám | Doctor được chỉ định | `confirmed`, đã đến giờ hẹn, có hồ sơ khám hợp lệ của mình gắn với lịch | `completed` |
| Bệnh nhân vắng | Receptionist | `confirmed`, đã qua giờ hẹn, kiểm tra thực tế bệnh nhân không đến | `no_show` |

Quy ước cho phần mới: không xác nhận khi bác sĩ còn chưa phản hồi; có thể từ chối hành chính một yêu cầu Pending trước khi bác sĩ phản hồi. Bác sĩ phản hồi một lần cho mỗi vòng Pending; gửi lại khi đã phản hồi phải trả xung đột, trừ khi Admin/lễ tân đã đổi lịch và reset phản hồi.

`rejected/cancelled/completed/no_show` là trạng thái kết thúc của lịch: không sửa giờ/bác sĩ, không xác nhận lại, không hủy tiếp. Muốn hẹn lại thì tạo lịch mới. Việc sửa bệnh án là nghiệp vụ khác, không mở lại trạng thái lịch.

### 3.4. Trường hợp lễ tân duyệt dù bác sĩ từ chối

Admin UI hiện có nhãn **Overridden by reception** khi phản hồi là `rejected` nhưng lịch là `confirmed/completed/no_show`. Module mới phải làm được đúng trường hợp này:

1. Hiển thị nguyên văn lý do bác sĩ từ chối cho lễ tân.
2. Có nút/checkbox xác nhận ngoại lệ riêng; không biến nó thành xác nhận mặc định.
3. Bắt buộc nhập `review_note`, giải thích căn cứ điều phối đã được xử lý với bác sĩ.
4. Chỉ cập nhật trạng thái chung và thông tin duyệt. Không sửa `doctor_response` thành Accepted để che phản hồi ban đầu.
5. UI Doctor và Admin đều vẫn thấy phản hồi ban đầu, ghi chú và người duyệt. Patient nhận trạng thái cuối và thông tin lịch cần thiết; không cần nhận ghi chú nội bộ.

### 3.5. Điều kiện tạo lịch và chọn hồ sơ

Backend kiểm tra lại tất cả điều kiện sau; dropdown chỉ giúp giảm nhập sai:

1. Bác sĩ có hồ sơ tồn tại, tài khoản hiện ở role `doctor`, `users.user_status=1`, `users.is_deleted=0`, `doctors.status=1`.
2. Bệnh nhân liên kết tài khoản phải ở role `patient`, hoạt động, chưa bị ẩn. Admin hiện vẫn hỗ trợ hồ sơ `patients.user_id IS NULL`; không tự loại bỏ hỗ trợ này khi refactor.
3. Patient online bắt buộc có hồ sơ liên kết đúng tài khoản của mình. Không được dùng chế độ “không có tài khoản” để chọn bệnh nhân khác.
4. `patient.user_id` và `doctor.user_id` không được bằng nhau. Một tài khoản có thể còn hai hồ sơ sau khi đổi role; chỉ hồ sơ đúng role hiện tại được chọn cho lịch mới.
5. Ngày giờ hợp lệ, nằm trong năm 2000–2100 theo validator hiện có và lớn hơn giờ DB. Lý do khám tối đa 255 ký tự.
6. Không có lịch `pending/confirmed` khác của cùng bác sĩ tại đúng thời điểm đó.
7. Không cho frontend chỉ định trạng thái, phản hồi bác sĩ, người duyệt hoặc giờ phản hồi khi tạo.

Nên ghi rõ hai giá trị ban đầu trong lệnh INSERT hoặc bảo đảm defaults của DB vẫn là `pending`; không dựa vào giá trị do trình duyệt gửi lên.

### 3.6. “Lịch trống” theo khả năng DB hiện tại

Hiện hệ thống chỉ chống trùng **cùng bác sĩ + cùng thời điểm bắt đầu**. DB tự tạo:

```text
slot_key = doctor_id + '|' + appointment_datetime   nếu lịch pending/confirmed
slot_key = NULL                                   nếu lịch đã kết thúc
UNIQUE uq_doctor_slot(slot_key)
```

- `pending` giữ chỗ giống `confirmed`.
- Bác sĩ từ chối nhưng lịch vẫn `pending` thì **vẫn giữ chỗ**. Chỉ sau quyết định Reject/Cancel cuối cùng mới giải phóng.
- Không lọc bỏ lịch của tài khoản bị ẩn khi tính giờ đã bị chiếm; lịch mở vẫn tồn tại và vẫn có thể giữ chỗ.
- Khi sửa, loại trừ chính `appointment_id` đang sửa khỏi phép kiểm tra trùng.
- Cột unique mới là lớp chặn hai người đặt cùng lúc. Đọc thấy trống không bảo đảm INSERT sau đó còn trống.
- DB hiện không có thời lượng khám, ca làm hay nghỉ phép, nên không thể khẳng định bác sĩ “rảnh thực tế” chỉ vì chưa có lịch tại thời điểm chọn.
- Hiện cũng chưa có ràng buộc chặn một bệnh nhân hẹn hai bác sĩ khác nhau cùng giờ. Đây là khoảng trống cần thống nhất nếu team mở rộng, không được ghi là đã được DB chặn.

**MVP khớp hệ thống hiện tại:** xem giờ đã bị chiếm trong ngày và kiểm tra thời điểm người dùng chọn; giao diện ghi “chưa có lịch tại thời điểm này”, không khẳng định đã kiểm tra ca làm.

**Nếu muốn khung giờ cố định/giờ làm thật:** thống nhất giờ mở cửa, phút mỗi lượt, nghỉ trưa/nghỉ phép và bổ sung cấu hình/bảng lịch làm việc. Cả Admin, Receptionist và Patient phải dùng chung validator. Không tự cho Patient đặt theo bước 30 phút trong khi Admin vẫn tạo giờ tùy ý rồi coi đó là kiểm tra chồng lấn đầy đủ.

### 3.7. Sửa lịch và reset phản hồi — phải giữ tương thích Admin

| Thay đổi | Xử lý |
|---|---|
| Chỉ sửa `visit_reason` | Không reset phản hồi hoặc quyết định cũ theo cách Admin đang làm |
| Đổi bác sĩ hoặc ngày giờ của lịch Pending | Đặt `doctor_response='pending'`; xóa lý do/thời điểm phản hồi cũ |
| Đổi bác sĩ hoặc ngày giờ của lịch Confirmed | Thực hiện reset ở trên, đưa `appointment_status='pending'`, xóa `reviewed_by/reviewed_at/review_note` để chờ duyệt lại |
| Muốn đổi bệnh nhân | Hủy lịch cũ và tạo lịch mới; không sửa `patient_id` của lịch đang có |
| Không đổi trường nào | Trả kết quả không có thay đổi, không reset lịch |

Khi đổi sang bác sĩ khác phải kiểm tra lại role/trạng thái bác sĩ. Thời điểm mới phải hợp lệ và tương lai; kiểm tra trùng, tự hẹn chính mình và phạm vi quyền trước khi lưu.

Lưu ý đối chiếu code: Admin hiện chỉ kiểm tra thời gian tương lai trong nhánh `timeChanged`; chưa chặn đủ trường hợp chỉ đổi bác sĩ mà giữ một giờ hẹn đã qua. Khi tách service chung, bổ sung kiểm tra này cho thao tác sắp lịch lại và cập nhật Admin tương ứng. Admin hiện cũng cho sửa lý do/hủy lịch mở đã qua giờ; đừng nhầm đây là quyền Patient, vì Patient bị giới hạn hủy trước giờ hẹn.

Nếu mở rộng cho Patient đổi lịch sau này, phải dùng cùng reset trên. Phạm vi hiện tại của Patient chỉ yêu cầu đặt mới và hủy, chưa yêu cầu sửa lịch.

### 3.8. Transaction và thao tác đồng thời

Các API ghi cần đọc lại bản ghi trong transaction bằng `SELECT ... FOR UPDATE` trước khi xét quyền/trạng thái và cập nhật. Các bước chính:

1. Xác thực tài khoản và vai trò từ DB.
2. Bắt đầu transaction; khóa lịch đang thao tác nếu có.
3. Kiểm tra chủ sở hữu từ bản ghi vừa đọc, trạng thái và điều kiện thời gian.
4. Với tạo/đổi lịch: kiểm tra hồ sơ và khung giờ; cập nhật lịch.
5. Commit; lỗi thì rollback. Lỗi unique `uq_doctor_slot` trả `409` và yêu cầu chọn lại giờ.

Ví dụ: Patient hủy trong lúc lễ tân duyệt. Thao tác khóa trước được xử lý trước; thao tác sau phải đọc trạng thái mới, không dùng dữ liệu cũ trên trình duyệt để ghi đè. Nếu lễ tân đã đổi giờ và reset, request phản hồi từ form bác sĩ mở trước đó không được xác nhận nhầm giờ mới.

Để chống form cũ chắc chắn, nên bổ sung `row_version` bằng migration chung: frontend gửi phiên bản đã đọc, backend so sánh sau khi khóa dòng, và tăng phiên bản ở mọi thay đổi liên quan qua Admin/Receptionist/Doctor/Patient. Trả 409 nếu phiên bản đã đổi. Không chỉ hash bác sĩ + thời gian + trạng thái rồi cho rằng đã đủ: nếu lịch bị đổi đi rồi đổi về các giá trị cũ thì form cũ có thể vẫn khớp hash. Cột phiên bản **chưa có trong schema hiện tại**; người phụ trách phần chung cần triển khai cùng tất cả API ghi, không để từng bạn tự thêm riêng.

Khi hủy chỉ đổi trạng thái, không DELETE lịch. Schema hiện chưa có người hủy, lý do hủy hoặc thời điểm hủy; không ghi các thông tin đó vào cột quyết định của lễ tân. Nếu cần audit, bổ sung migration/bảng lịch sử dùng chung trước.

## 4. Module Receptionist — bạn A

### 4.1. Các chức năng ngoài lịch hẹn — mô tả ngắn

| Chức năng | Tối thiểu phải làm được | Giới hạn |
|---|---|---|
| Đăng ký bệnh nhân tại quầy | Nhập họ tên, ngày sinh, giới tính, SĐT, địa chỉ; tạo liên kết `users` + `patients` trong một transaction | Role do server cố định là Patient; không tạo Admin/Doctor. Không cho nhập tiền sử bệnh |
| Sửa hành chính | Sửa SĐT ở `users.phone_number`, địa chỉ ở `patients.address` của đúng hồ sơ được chọn | Không cho update `medical_history`, role, mật khẩu hoặc trạng thái tài khoản qua API này |
| Tìm bệnh nhân | Theo tên hoặc SĐT, trả thông tin hành chính tối thiểu | Không trả bệnh án, đơn thuốc, kết quả xét nghiệm hoặc tiền sử bệnh |
| Hóa đơn có khoản mục | Thêm khoản khám/thuốc/xét nghiệm với số lượng, đơn giá; backend tính tổng | Chưa có `bill_items`; cần migration mới, không chỉ nối chuỗi vào `notes` rồi coi là đủ chi tiết |
| Thu tiền | Chọn Cash/Card/Bank Transfer; ghi số tiền đã nhận hoặc trả toàn bộ phần còn nợ | Giữ quy tắc Bills: không vượt dư nợ, không sửa trực tiếp số đã thu để hoàn tiền, không hủy hóa đơn đã thu tiền |
| Thông báo | Gửi xác nhận khi Confirmed; nhắc lịch còn Confirmed, trong tương lai và chưa nhắc lần đó | Cần provider/config email/SMS và lưu kết quả gửi; không giả báo đã gửi khi chỉ tạo thông báo trên UI |

**Đăng ký tại quầy với schema hiện tại:** tên/SĐT nằm trong `users`; `patients` không có hai cột này, nên không chỉ INSERT một dòng `patients.user_id=NULL` rồi kỳ vọng tìm theo tên/SĐT được. Tạo user Patient cùng hồ sơ, sinh username duy nhất và xử lý thông tin đăng nhập ban đầu theo quy trình bàn giao an toàn của team; không dùng một mật khẩu mặc định chung. Email có thể NULL trong DB nếu quầy chưa thu thập được. Không lấy API tạo User của Admin vốn bắt buộc email rồi tự bỏ kiểm tra quyền.

Không biết ngày sinh/địa chỉ thì ghi NULL theo chính sách nhập liệu; không để defaults `1999-01-01` hoặc địa chỉ mặc định hiện có biến thành thông tin bệnh nhân thật. SĐT trong schema đang là `VARCHAR(10)`; nếu cần số dài hơn phải có migration chung và sửa validator ở tất cả module liên quan.

Hóa đơn theo khoản mục phải có giao dịch duy nhất cho `bills` và các dòng chi tiết, tính tiền ở server và dùng số tiền chính xác. Nếu tái sử dụng `bills_service.php`, chú ý service hiện tự mở transaction; không gọi nó bên trong một transaction khác mà chưa refactor. Lễ tân không đọc bảng đơn thuốc để xem điều trị; dữ liệu đưa sang tính phí chỉ là khoản mục tài chính được cho phép, không kèm chẩn đoán/liều dùng/ghi chú lâm sàng.

### 4.2. Màn hình lịch hẹn cần có

- Danh sách lịch toàn quầy, lọc ngày, bác sĩ, trạng thái chung và phản hồi bác sĩ.
- Form tạo lịch: chọn bệnh nhân → bác sĩ/chuyên khoa → ngày → xem giờ đã bị chiếm → chọn giờ → lý do khám → lưu Pending.
- Trang/khung chi tiết: người bệnh, bác sĩ, giờ, lý do khám, hai trạng thái, phản hồi bác sĩ, người/giờ/ghi chú duyệt.
- Nút Sửa/Hủy chỉ cho lịch mở; nút Approve/Reject chỉ ở Pending; nút xác nhận ngoại lệ riêng khi bác sĩ Rejected; No-show chỉ khi đủ điều kiện.
- Lịch trống theo ngày chỉ cần thông tin thời gian và khả năng nhận lịch, không cần tải dữ liệu điều trị.

### 4.3. API đề xuất và các bước xử lý

| Action | Kiểm tra bắt buộc |
|---|---|
| `list` / `detail` | Đúng role Receptionist; trả trường hành chính/lịch hẹn được phép |
| `availability` | Bác sĩ tồn tại, đang nhận lịch; ngày hợp lệ; chỉ trả giờ bị chiếm/khả năng đặt |
| `create` | Toàn bộ điều kiện mục 3.5; luôn khởi tạo Pending, không tự duyệt |
| `update` | Khóa lịch, chỉ Pending/Confirmed, bệnh nhân cố định, xử lý reset mục 3.7 |
| `cancel` | Khóa lịch, chỉ Pending/Confirmed; đổi Cancelled, giữ dữ liệu lịch sử |
| `approve` | Khóa lịch; Pending; bác sĩ đã trả lời; kiểm tra thời gian/hồ sơ/slot; xử lý ngoại lệ nếu cần |
| `reject` | Khóa lịch; Pending; bắt buộc ghi chú; lưu thông tin người duyệt từ session |
| `mark_no_show` | Khóa lịch; Confirmed; đã qua giờ hẹn; chuyển No-show |

Khi Approve/Reject: `reviewed_by` phải là ID user lễ tân đang đăng nhập, `reviewed_at` lấy NOW(); không nhận hai giá trị này từ form. Xác nhận lịch giữ nguyên phản hồi của bác sĩ. Lịch được bác sĩ Accepted chỉ được chuyển Confirmed sau thao tác của lễ tân.

Lịch cũ của tài khoản đã ẩn vẫn phải hiển thị để xử lý lịch sử. Không cho chọn hồ sơ đó để tạo mới. Với các lịch tương lai đã có nhưng tài khoản bị khóa/ẩn, hiển thị cảnh báo để lễ tân xử lý hủy/điều phối; không tự xóa hàng loạt hoặc giấu mất lịch khỏi danh sách.

**Đạt khi:** tạo lịch tại quầy xuất hiện ở đúng Doctor và Patient; duyệt làm cả ba màn hình thấy trạng thái mới; sửa giờ làm mất phản hồi/duyệt cũ đúng quy tắc; không đọc được bất kỳ API dữ liệu điều trị nào.

## 5. Module Doctor — bạn B

### 5.1. Các chức năng ngoài lịch hẹn — mô tả ngắn

| Chức năng | Tối thiểu phải làm được | Giới hạn |
|---|---|---|
| Danh sách bệnh nhân của mình | Tra từ lịch hẹn/hồ sơ điều trị thuộc bác sĩ hiện tại | Không liệt kê toàn bộ bệnh nhân bệnh viện |
| Hồ sơ cơ bản và tiền sử bệnh | Xem người bệnh đã có lịch Confirmed/Completed với mình; cập nhật `patients.medical_history` trong phạm vi điều trị | Khi chỉ Pending, chỉ trả thông tin cần đánh giá yêu cầu lịch, chưa mở toàn bộ bệnh án |
| Bệnh án | Ghi `medical_records`: bệnh nhân, bác sĩ, lịch hẹn, triệu chứng, chẩn đoán, điều trị, ghi chú | Các ID phải nhất quán với lịch; không nhận `doctor_id` tùy ý từ form |
| Đơn thuốc | Tạo `prescriptions` và `prescription_details`, nhập thuốc, liều, tần suất, thời gian, số lượng, hướng dẫn | Bệnh án/đơn thuốc phải của mình; không sửa đơn của bác sĩ khác |
| Xét nghiệm/tiến triển | Nhập `test_results`, kết quả, khoảng tham chiếu, trạng thái; ghi tiến triển trong notes của bệnh án | Chỉ trong phạm vi điều trị của mình |
| Lịch sử điều trị | Xem các lần khám/bệnh án do mình phụ trách của bệnh nhân đó | Không mở rộng thành đọc bệnh án bác sĩ khác; xem mục 2.2 |

Chú ý tên cột thật: `prescriptions.PrescriptionDate`, `prescriptions.Notes`, `prescription_details.prescription_detailID`, `test_results.Status`. Không tự giả định các cột đã được đổi sang snake_case.

### 5.2. Màn hình lịch cá nhân

1. Lịch theo ngày/tuần; mọi truy vấn có `doctor_id` lấy từ session qua bảng doctors.
2. Danh sách yêu cầu Pending cần phản hồi, ghi rõ bệnh nhân, giờ, lý do đến khám và trạng thái phản hồi hiện tại.
3. Nút Accept/Reject chỉ xuất hiện cho yêu cầu hợp lệ. Reject có ô lý do bắt buộc.
4. Lịch đã Accepted nhưng vẫn Pending phải hiển thị “chờ lễ tân duyệt”, không hiển thị như lịch đã xác nhận.
5. Lịch được duyệt ngoại lệ hiển thị phản hồi từ chối cũ và ghi chú lễ tân; không đổi nó thành Accepted trên UI.
6. Confirmed và đã đến giờ mới có thao tác hoàn tất khám. Các lịch kết thúc chỉ còn thao tác xem phù hợp.

### 5.3. API phản hồi và hoàn tất

**`respond`:** nhận `appointment_id`, quyết định `accepted/rejected`, lý do khi từ chối và fingerprint của lịch đang xem. Backend khóa lịch, kiểm tra bác sĩ sở hữu, trạng thái Pending, giờ tương lai, phản hồi chưa được thực hiện và phiên bản khớp. Cập nhật `doctor_response`, `doctor_reject_reason`, `doctor_responded_at`; **không cập nhật** trạng thái chung hoặc trường duyệt lễ tân. Khi Accepted, xóa lý do từ chối nếu có dữ liệu cũ không còn hợp lệ.

**`complete`:** khóa lịch, kiểm tra đúng bác sĩ, trạng thái Confirmed và đã đến giờ. Kiểm tra hồ sơ khám gắn đúng bệnh nhân/bác sĩ/lịch hẹn, chẩn đoán không rỗng. Ghi bệnh án và hoàn tất lịch trong transaction phù hợp; không để một bên lưu thành công nhưng bên kia thất bại. Nếu lưu bệnh án trước rồi hoàn tất bằng nút riêng, API hoàn tất phải kiểm tra bệnh án đã tồn tại và thuộc đúng lịch.

Doctor không có nút/API đổi sang bác sĩ khác, tự duyệt Pending thành Confirmed, sửa giờ hẹn hoặc sửa hóa đơn. API đọc chi tiết phải chặn cả trường hợp thay `appointment_id`, `patient_id`, `record_id`, `prescription_id` hoặc `test_result_id` sang dữ liệu ngoài phạm vi.

**Đạt khi:** bác sĩ chỉ thấy lịch/bệnh nhân trong phạm vi của mình; phản hồi xuất hiện ở quầy nhưng chưa tự xác nhận; chỉ hoàn tất lịch được duyệt; sửa ID không vượt được sang dữ liệu bác sĩ khác.

## 6. Module Patient — bạn C

### 6.1. Các chức năng ngoài lịch hẹn — mô tả ngắn

| Chức năng | Tối thiểu phải làm được | Giới hạn |
|---|---|---|
| Đăng ký/đăng nhập | Tạo user Patient và hồ sơ liên kết trong một transaction; validate username/email/SĐT, hash mật khẩu | Public registration không được nhận role Admin/Doctor/Receptionist, trạng thái hoặc quyền từ client |
| Hồ sơ cá nhân | Xem/sửa SĐT, email, địa chỉ; tái sử dụng Account API cho trường nó đã hỗ trợ | Địa chỉ cần API Patient riêng; không cho update `medical_history` |
| Lịch sử khám | Xem chẩn đoán, đơn thuốc, xét nghiệm của bản thân | Không xem của người khác; không sửa nội dung điều trị |
| Hóa đơn | Xem hóa đơn, số đã trả, còn nợ, trạng thái của bản thân | Không tự gửi Paid/paid_amount để đánh dấu đã thanh toán; chưa có thanh toán online thực tế |
| Thông báo | Nhận email/SMS xác nhận/nhắc lịch qua cơ chế chung của bạn A | Không tự gán trạng thái gửi thành công hoặc tạo reminder tùy ý cho người khác |

Quy ước công bố kết quả cho MVP cần thực hiện nhất quán: lịch sử điều trị cho Patient chỉ lấy hồ sơ của mình gắn với lịch đã Completed; xét nghiệm chỉ hiển thị kết quả Completed. DB hiện chưa có trạng thái công bố bệnh án/đơn thuốc. Với bệnh án không gắn lịch (`appointment_id=NULL`), chưa tự công bố cho Patient nếu chưa có quy tắc phát hành khác được team thống nhất. Không để dữ liệu bác sĩ đang nhập dở trở thành kết quả cuối cùng chỉ vì đã INSERT một dòng.

### 6.2. Luồng đặt lịch online chi tiết

1. Patient đăng nhập bằng tài khoản hoạt động, có role Patient và hồ sơ liên kết hợp lệ.
2. Xem danh sách bác sĩ đang nhận lịch; tìm theo chuyên khoa. Chỉ trả họ tên, chuyên khoa, thông tin phòng cần thiết; không kèm bệnh nhân khác hoặc dữ liệu điều trị.
3. Chọn bác sĩ và ngày. Gọi API availability; chỉ nhận giờ bị chiếm/khả năng đặt, không nhận tên/SĐT/lý do khám của người đang chiếm giờ đó.
4. Chọn thời điểm còn tương lai và chưa bị chiếm theo định nghĩa hiện tại ở mục 3.6; nhập lý do khám nếu có.
5. Bấm Đặt lịch. Backend tự lấy `patient_id` của người đang đăng nhập và kiểm tra lại tất cả điều kiện mục 3.5.
6. Nếu người khác vừa lấy cùng giờ, trả 409, tải lại lịch trống, cho chọn giờ khác. Không báo thành công khi INSERT thất bại.
7. Thành công: hiển thị Pending/chờ bác sĩ và lễ tân xử lý. Danh sách “Lịch hẹn của tôi” có lịch mới cùng bác sĩ, thời gian và lý do.
8. Doctor Accepted: Patient vẫn thấy chờ lễ tân xác nhận. Doctor Rejected: không tự suy ra lịch đã bị hủy/từ chối cuối; hiển thị lịch còn chờ quyết định nếu trạng thái chung vẫn Pending.
9. Khi lễ tân Confirmed, hiển thị lịch đã xác nhận và gửi xác nhận qua cơ chế chung. Khi Rejected/Cancelled, hiển thị đúng trạng thái cuối; không còn nhắc đi khám như một lịch Confirmed.

### 6.3. Xem và hủy lịch cá nhân

- API `list/detail` luôn giới hạn `patient_id` từ session, không lấy theo patient_id tùy chọn của frontend.
- Nút Hủy chỉ hiển thị khi Pending/Confirmed và còn trước giờ hẹn. Backend vẫn kiểm tra bằng giờ DB; không chỉ dựa vào đồng hồ máy người dùng.
- API `cancel` khóa lịch, kiểm tra quyền sở hữu, phiên bản và thời gian; thành công chỉ đổi thành Cancelled.
- Sau giờ hẹn, Patient không được hủy, kể cả request tự gửi trực tiếp. Patient cũng không được tự đánh dấu Completed/No-show.
- Không có quyền sửa bác sĩ/giờ của lịch hiện tại trong phạm vi này. Muốn đổi lịch: hủy trước giờ rồi đặt lịch mới; không sửa trực tiếp bản ghi đã Confirmed.
- Khi Admin/lễ tân đổi giờ/bác sĩ và đưa lịch về Pending, màn hình Patient phải cập nhật đúng thông tin mới; thông báo xác nhận cũ không được coi là trạng thái hiện tại.

**Đạt khi:** tài khoản A không đọc/hủy được lịch, bệnh án hoặc hóa đơn của B dù biết UUID; lịch đặt online xuất hiện ở quầy và đúng bác sĩ; hủy giải phóng khung giờ; Pending/Accepted/Confirmed không bị hiển thị lẫn nhau.

## 7. Hợp đồng API và phần dùng chung trước khi ghép

Các URL bên dưới là **đề xuất cho module mới**, không phải endpoint đã tồn tại. Giữ Admin endpoint hiện tại hoạt động.

| Module | Nhóm action lịch hẹn đề xuất |
|---|---|
| Receptionist `appointments_feature/appointments_api.php` | `list`, `detail`, `availability`, `create`, `update`, `cancel`, `approve`, `reject`, `mark_no_show` |
| Doctor `appointments_feature/appointments_api.php` | `list`, `detail`, `respond`, `complete` |
| Patient `appointments_feature/appointments_api.php` | `doctors`, `availability`, `list`, `detail`, `create`, `cancel` |

Quy ước dùng GET cho đọc, POST FormData cho ghi để tương thích phong cách hiện tại. Các action tạo/sửa dùng tên trường DB đang có: `patient_id` (chỉ vai trò được chọn bệnh nhân), `doctor_id`, `appointment_datetime`, `visit_reason`; các action trên bản ghi dùng `id` là appointment_id. Doctor/Patient không nhận trường danh tính bản thân từ request.

Response thống nhất `success`, `message`, `data` khi có; trả HTTP đúng loại lỗi. API availability cho Patient chỉ trả ví dụ `date`, `doctor_id`, `busy_times` hoặc danh sách thời điểm được chọn; không trả nguyên danh sách appointments toàn bệnh viện. Phải ghi rõ thông tin chỉ phản ánh lúc truy vấn; backend tiếp tục kiểm tra lúc lưu.

Ba bạn cần thống nhất trước khi code:

1. Tên action/đường dẫn và cách dùng session chung; bổ sung redirect Receptionist trong login backend. Sửa luồng trả lỗi để role chưa hỗ trợ không rơi vào nhánh đóng statement lần hai.
2. Helper xác thực theo role; helper xác định doctor_id/patient_id từ user_id.
3. Validator lịch hẹn/availability dùng chung với Admin; quy tắc phiên bản để chống form cũ.
4. Cách frontend cập nhật sau thao tác: tải lại API hoặc polling khi tab hoạt động, để ba vai trò thấy thay đổi. Không cần WebSocket cho MVP.
5. Cơ chế gửi thông báo: chỉ gửi sau commit thành công; lưu/retry có chống gửi trùng. Lỗi nhà cung cấp không được biến một lịch đã commit thành thông báo “đặt lịch thất bại” khiến người dùng tạo trùng.
6. Migration riêng cho bill_items, thông báo và lịch làm việc nếu mở rộng. Không tự sửa ENUM trạng thái lịch hẹn hoặc bỏ unique `uq_doctor_slot` trên máy cá nhân.

## 8. Checklist nghiệm thu phần lịch hẹn

| Tình huống kiểm tra | Kết quả phải đạt |
|---|---|
| Patient tạo lịch hợp lệ | Pending + phản hồi Pending; xuất hiện ở quầy/đúng Doctor |
| Receptionist tạo thay bệnh nhân | Cùng luồng Pending, không tự Confirmed |
| Doctor Accept | Chỉ phản hồi Accepted; lịch vẫn Pending |
| Doctor Reject không có lý do | Bị từ chối; không vi phạm CHECK trong DB |
| Doctor Reject hợp lệ | Lịch vẫn Pending và vẫn chiếm giờ cho đến quyết định cuối |
| Receptionist duyệt khi Doctor Accepted | Confirmed; đúng ID người duyệt và giờ DB |
| Receptionist duyệt khi Doctor chưa phản hồi | Bị chặn |
| Receptionist override Doctor Rejected | Có xác nhận ngoại lệ + ghi chú; phản hồi cũ giữ nguyên; Admin thấy nhãn override |
| Receptionist từ chối lịch | Rejected; khung giờ được giải phóng |
| Hai người đặt cùng bác sĩ/cùng giờ | Chỉ một lịch mở được tạo; người còn lại nhận 409 |
| Chọn bác sĩ bị khóa/tắt/ẩn/sai role | Không có trong lựa chọn mới; gọi API trực tiếp cũng bị chặn |
| User có cả hồ sơ bác sĩ và bệnh nhân cũ | Chỉ chọn được hồ sơ đúng role; không tự hẹn chính mình |
| Đổi giờ/bác sĩ của Confirmed | Trở về Pending, reset phản hồi và xóa quyết định duyệt |
| Chỉ đổi lý do khám | Giữ trạng thái/phản hồi theo quy tắc hiện tại |
| Patient hủy lịch trước giờ | Cancelled, giải phóng giờ, không xóa bản ghi |
| Patient hủy sau giờ hoặc lịch của người khác | Bị chặn tại API |
| Doctor đọc/respond/complete lịch của bác sĩ khác | Bị chặn tại API |
| Hoàn tất Pending hoặc chưa đến giờ | Bị chặn |
| Hoàn tất Confirmed hợp lệ có hồ sơ khám | Completed; Patient xem được kết quả theo quy tắc công bố |
| Lễ tân cố đọc diagnosis/prescription/test_result/medical_history | Không nhận được dữ liệu, kể cả qua API chi tiết |
| Duyệt/hủy/đổi giờ/phản hồi đồng thời hoặc dùng form cũ | Không ghi đè trạng thái mới, không phản hồi nhầm giờ; trả xung đột khi cần |
| Tài khoản bị ẩn sau khi đã có lịch | Không tạo mới/đăng nhập tiếp; lịch cũ vẫn còn cho người có quyền xử lý |
| Gửi email/SMS thất bại | Lịch đã lưu vẫn thành công; thông báo có trạng thái lỗi và cách gửi lại |

## 9. Thứ tự ghép và bàn giao

1. Chốt hợp đồng mục 3 và 7; tách helper dùng chung và giữ các kiểm tra Admin hiện tại.
2. Mỗi bạn hoàn thành login/guard/dashboard đúng role trước, rồi làm API lịch hẹn trước giao diện phụ.
3. Ghép luồng Patient tạo → Doctor trả lời → Receptionist duyệt → Doctor hoàn tất → Patient xem lịch sử.
4. Ghép Receptionist tạo/sửa/hủy và kiểm tra thay đổi xuất hiện ở các vai trò còn lại.
5. Chạy checklist mục 8, đặc biệt sửa ID, trùng giờ và thao tác đồng thời.
6. Sau đó hoàn thiện các chức năng phụ; migration nào cũng kèm file SQL và cập nhật bản DB dùng chung cho team.

Mỗi người bàn giao: folder module, danh sách API/action, migration mới nếu có, tài khoản test theo role, các ca kiểm tra đã chạy và phần còn thiếu. Không dùng dữ liệu giả hoặc chỉ giấu nút để đánh dấu chức năng đã hoàn thành.

## 10. Nguồn đối chiếu trong project

- [API lịch hẹn Admin](../admin_form/appointments_feature/appointments_api.php): tạo/sửa/hủy, điều kiện role, tự hẹn chính mình, reset phản hồi.
- [Giao diện lịch hẹn Admin](../admin_form/appointments_feature/appointments.js): hai trạng thái và nhận diện override.
- [Đăng nhập](../login_feature/login_back_end.php): session, redirect và nhánh còn thiếu.
- [Guard Admin](../admin_form/php/auth_guard.php), [Account API](../admin_form/php/account_api.php): xác thực còn hiệu lực và tài khoản cá nhân.
- [Bills service](../admin_form/bills_feature/bills_service.php): tiền, trạng thái thanh toán, phiên bản và giới hạn hiện tại.
- [Database export](../database_export/hospital_management_system_20261115_143552/hospital_management_system.sql): cấu trúc để team import. Tài liệu này cũng đã đối chiếu schema MySQL đang chạy; không trích dữ liệu cá nhân vào hướng dẫn.
