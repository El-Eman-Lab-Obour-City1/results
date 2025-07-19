<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <title>قائمة المرضى</title>
  <style>
    body {
      font-family: Arial, sans-serif;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }

    th, td {
      padding: 10px;
      border: 1px solid #ccc;
      text-align: center;
    }

    .delete-btn {
      color: red;
      cursor: pointer;
      text-decoration: none;
    }
  </style>
</head>
<body>

<h2>قائمة المرضى</h2>

<table>
  <thead>
    <tr>
      <th>الاسم</th>
      <th>النوع</th>
      <th>رقم الهاتف</th>
      <th>الإجراء</th>
    </tr>
  </thead>
  <tbody id="patients-table">
    <tr>
      <td>أحمد محمد</td>
      <td>ذكر</td>
      <td>01012345678</td>
      <td><a href="#" class="delete-btn">🗑 حذف</a></td>
    </tr>
    <tr>
      <td>سارة علي</td>
      <td>أنثى</td>
      <td>01122334455</td>
      <td><a href="#" class="delete-btn">🗑 حذف</a></td>
    </tr>
    <tr>
      <td>محمود خالد</td>
      <td>ذكر</td>
      <td>01233445566</td>
      <td><a href="#" class="delete-btn">🗑 حذف</a></td>
    </tr>
  </tbody>
</table>

<script>
  // حذف الصف بدون ريفرش
  document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.preventDefault(); // منع فتح الرابط
      if (confirm("هل أنت متأكد من الحذف؟")) {
        // حذف الصف من الجدول
        this.closest('tr').remove();
      }
    });
  });
</script>

</body>
</html>