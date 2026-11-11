# Hospital Management System

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
# HMS_web_project
This project is a hospital management website which is built by a group of students who want to learn how a website acctually works. It's flaw and we'll try to improve.
