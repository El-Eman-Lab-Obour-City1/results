<?php
$dataFile = "data.json";
$patients = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];

$message = "";
$editMode = false;
$patientToEdit = null;
$searchQuery = "";

// معالجة طلب البحث
if (isset($_GET['search'])) {
    $searchQuery = trim($_GET['search']);
}

// معالجة طلب حذف المريض
if (isset($_GET['delete_patient'])) {
    $phoneToDelete = $_GET['delete_patient'];
    foreach ($patients as $index => $patient) {
        if ($patient['phone'] === $phoneToDelete) {
            // حذف ملفات PDF المرتبطة بالمريض أولاً
            foreach ($patient['tests'] as $test) {
                if (file_exists($test['file'])) {
                    unlink($test['file']);
                }
            }
            // حذف المريض من المصفوفة
            unset($patients[$index]);
            // إعادة ترقيم المصفوفة
            $patients = array_values($patients);
            // حفظ التغييرات
            file_put_contents($dataFile, json_encode($patients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $message = "<div class='alert alert-success'>✅ تم حذف المريض بنجاح.</div>";
            break;
        }
    }
}

// معالجة طلب حذف ملف معين
if (isset($_GET['delete_file'])) {
    $phone = $_GET['phone'];
    $fileToDelete = $_GET['delete_file'];
    
    foreach ($patients as &$patient) {
        if ($patient['phone'] === $phone) {
            foreach ($patient['tests'] as $index => $test) {
                if ($test['file'] === $fileToDelete) {
                    // حذف الملف الفعلي من السيرفر
                    if (file_exists($fileToDelete)) {
                        unlink($fileToDelete);
                    }
                    // حذف الملف من مصفوفة الاختبارات
                    unset($patient['tests'][$index]);
                    // إعادة ترقيم المصفوفة
                    $patient['tests'] = array_values($patient['tests']);
                    // حفظ التغييرات
                    file_put_contents($dataFile, json_encode($patients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $message = "<div class='alert alert-success'>✅ تم حذف الملف بنجاح.</div>";
                    break 2;
                }
            }
        }
    }
}

// معالجة طلب تعديل المريض
if (isset($_GET['edit_patient'])) {
    $phoneToEdit = $_GET['edit_patient'];
    foreach ($patients as $patient) {
        if ($patient['phone'] === $phoneToEdit) {
            $patientToEdit = $patient;
            $editMode = true;
            break;
        }
    }
}

// معالجة طلب تحديث حالة الدفع
if (isset($_GET['toggle_payment'])) {
    $phone = $_GET['toggle_payment'];
    foreach ($patients as &$patient) {
        if ($patient['phone'] === $phone) {
            $patient['is_paid'] = !$patient['is_paid'];
            file_put_contents($dataFile, json_encode($patients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $message = "<div class='alert alert-success'>✅ تم تحديث حالة الدفع.</div>";
            header("Location: upload.php");
            exit();
        }
    }
}

// دالة لإضافة رمز الدولة إذا لم يكن موجوداً
function formatPhoneNumber($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // إذا بدأ الرقم بـ 0، نستبدلها بـ +20
    if (substr($phone, 0, 1) === '0') {
        $phone = '+20' . substr($phone, 1);
    }
    // إذا كان الرقم يبدأ بـ 20 بدون +، نضيف +
    elseif (substr($phone, 0, 2) === '20') {
        $phone = '+' . $phone;
    }
    // إذا لم يكن هناك رمز دولة مطلقاً، نضيف +20
    elseif (substr($phone, 0, 1) !== '+') {
        $phone = '+20' . $phone;
    }
    
    return $phone;
}

// معالجة طلب إرسال عبر الواتساب
if (isset($_GET['send_whatsapp'])) {
    $phone = $_GET['send_whatsapp'];
    $patient = null;
    foreach ($patients as $p) {
        if ($p['phone'] === $phone) {
            $patient = $p;
            break;
        }
    }
    
    if ($patient) {
        $whatsappNumber = formatPhoneNumber($patient['phone']);
        $message = "عميلنا العزيز " . $patient['name'] . "، نتائج تحاليلك جاهزة. يمكنك الاطلاع عليها عبر التطبيق أو زيارة المعمل.";
        $whatsappUrl = "https://wa.me/$whatsappNumber?text=" . urlencode($message);
        header("Location: $whatsappUrl");
        exit();
    }
}

// معالجة طلب إرسال SMS
if (isset($_GET['send_sms'])) {
    $phone = $_GET['send_sms'];
    $patient = null;
    foreach ($patients as $p) {
        if ($p['phone'] === $phone) {
            $patient = $p;
            break;
        }
    }
    
    if ($patient) {
        $smsMessage = "عميلنا العزيز برجاء التوجه الى المعمل لاستلام النتائج أو الدخول الى ال Mobile App الخاص بالمعمل وتسجيل الدخول لرؤية النتائج الخاصه بك";
        
        foreach ($patients as &$p) {
            if ($p['phone'] === $phone) {
                $p['sms_message'] = $smsMessage;
                $p['send_sms'] = true;
                break;
            }
        }
        
        file_put_contents($dataFile, json_encode($patients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $message = "<div class='alert alert-success'>✅ تم إعداد رسالة SMS للارسال: $smsMessage</div>";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $isPaid = isset($_POST["is_paid"]) ? (bool)$_POST["is_paid"] : false;
    $sendToWhatsapp = isset($_POST["send_to_whatsapp"]) ? true : false;
    $sendSMS = isset($_POST["send_sms"]) ? true : false;
    $smsMessage = trim($_POST["sms_message"]);

    $patientName = trim($_POST["patient_name"]);
    $phone = trim($_POST["phone"]);
    $testDate = $_POST["test_date"];

    $uploadDir = "uploads/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $files = $_FILES["pdf_files"];
    $uploadSuccess = true;
    $uploadedFiles = [];

    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $uploadSuccess = false;
            $message = "<div class='alert alert-danger'>❌ خطأ أثناء رفع الملف.</div>";
            break;
        }

        $fileType = strtolower(pathinfo($files["name"][$i], PATHINFO_EXTENSION));
        if ($fileType !== "pdf") {
            $uploadSuccess = false;
            $message = "<div class='alert alert-danger'>❌ الملف يجب أن يكون PDF فقط.</div>";
            break;
        }

        $newFileName = preg_replace('/\s+/', '_', $patientName) . "_" . time() . "_" . $i . ".pdf";
        $destination = $uploadDir . $newFileName;

        if (move_uploaded_file($files["tmp_name"][$i], $destination)) {
            $uploadedFiles[] = [
                "date" => $testDate,
                "file" => $destination
            ];
        } else {
            $uploadSuccess = false;
            $message = "<div class='alert alert-danger'>❌ خطأ أثناء رفع الملف.</div>";
            break;
        }
    }

    if ($uploadSuccess && !empty($uploadedFiles)) {
        $foundIndex = null;
        foreach ($patients as $index => $p) {
            if ($p["phone"] === $phone) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex !== null) {
            // تحديث بيانات المريض
            $patients[$foundIndex]["name"] = $patientName;
            $patients[$foundIndex]["is_paid"] = $isPaid;
            $patients[$foundIndex]["send_to_whatsapp"] = $sendToWhatsapp;
            $patients[$foundIndex]["send_sms"] = $sendSMS;
            $patients[$foundIndex]["sms_message"] = $smsMessage;
            
            foreach ($uploadedFiles as $file) {
                $patients[$foundIndex]["tests"][] = $file;
            }
        } else {
            $patients[] = [
                "name" => $patientName,
                "phone" => $phone,
                "is_paid" => $isPaid,
                "send_to_whatsapp" => $sendToWhatsapp,
                "send_sms" => $sendSMS,
                "sms_message" => $smsMessage,
                "tests" => $uploadedFiles
            ];
        }

        file_put_contents($dataFile, json_encode($patients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $message = "<div class='alert alert-success'>✅ تم رفع الملفات وتحديث البيانات.</div>";
        
        if ($sendToWhatsapp) {
            $whatsappNumber = formatPhoneNumber($phone);
            $message = "عميلنا العزيز " . $patientName . "، نتائج تحاليلك جاهزة. يمكنك الاطلاع عليها عبر التطبيق أو زيارة المعمل.";
            $whatsappUrl = "https://wa.me/$whatsappNumber?text=" . urlencode($message);
            header("Location: $whatsappUrl");
            exit();
        }
    }
}

// تصفية المرضى بناء على استعلام البحث إذا وجد
$filteredPatients = $patients;
if (!empty($searchQuery)) {
    $filteredPatients = array_filter($patients, function($patient) use ($searchQuery) {
        return stripos($patient['name'], $searchQuery) !== false || 
               stripos($patient['phone'], $searchQuery) !== false;
    });
}

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>رفع نتيجة تحليل</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Tajawal', sans-serif;
        }
        .header {
            background: linear-gradient(135deg, #5a1f62 0%, #5a1f62 100%);
            color: white;
            padding: 2rem 0;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .upload-form {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }
        .results-table {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        .table th {
            background-color: #f1f7fd;
            font-weight: 600;
        }
        .btn-primary {
            background-color: #ef71aa;
            border-color: #ef71aa;
        }
        .btn-primary:hover {
            background-color: #fa4c9a;
            border-color: #fa4c9a;
        }
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }
        .btn-danger:hover {
            background-color: #bb2d3b;
            border-color: #bb2d3b;
        }
        .btn-success {
            background-color: #28a745;
            border-color: #28a745;
        }
        .btn-success:hover {
            background-color: #218838;
            border-color: #218838;
        }
        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
        }
        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #e0a800;
        }
        .btn-info {
            background-color: #17a2b8;
            border-color: #17a2b8;
        }
        .btn-info:hover {
            background-color: #138496;
            border-color: #138496;
        }
        .pdf-link {
            color: #d9534f;
            text-decoration: none;
        }
        .pdf-link:hover {
            text-decoration: underline;
        }
        .action-buttons {
            white-space: nowrap;
        }
        .file-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }
        .payment-status {
            display: inline-block;
            padding: 0.35em 0.65em;
            font-size: 0.75em;
            font-weight: 700;
            line-height: 1;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 0.25rem;
        }
        .paid {
            background-color: #28a745;
            color: white;
        }
        .unpaid {
            background-color: #dc3545;
            color: white;
        }
        .search-box {
            margin-bottom: 20px;
        }
        .search-box .input-group {
            max-width: 500px;
        }
    .logo-container {
      position: absolute;
      top: 5px;
      right: 20px;

    }

    .logo-container img {
      height: 150px; /* حجم اللوجو */
      width: auto;
  }
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap');
    </style>
</head>

<body>
    <div class="header text-center">

        <div class="container">
            <h1><i class="fas fa-upload"></i> رفع نتيجة تحليل</h1>
            <p class="lead">قم برفع نتائج التحاليل الجديدة للمرضى</p>
<div class="logo-container">
    <img src="asd.png" alt="Logo">
  </div>
        </div>
    </div>

    <div class="container">
        <div class="upload-form">
            <form action="" method="post" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">اسم المريض:</label>
                    <input type="text" class="form-control form-control-lg" name="patient_name" required 
                           value="<?= $editMode ? htmlspecialchars($patientToEdit['name']) : '' ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">رقم الهاتف:</label>
                    <input type="text" class="form-control form-control-lg" name="phone" required 
                           value="<?= $editMode ? htmlspecialchars($patientToEdit['phone']) : '' ?>" 
                           <?= $editMode ? 'readonly' : '' ?>>
                </div>

                <div class="mb-3">
                    <label class="form-label">تاريخ التحليل:</label>
                    <input type="date" class="form-control form-control-lg" name="test_date" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">رفع ملفات التحليل (PDF):</label>
                    <input type="file" class="form-control" name="pdf_files[]" accept="application/pdf" multiple <?= $editMode ? '' : 'required' ?>>
                    <small class="text-muted">اضغط زر Ctrl لتحديد أكثر من ملف</small>
                </div>

                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" name="is_paid" id="is_paid" 
                           <?= ($editMode && $patientToEdit['is_paid']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_paid">تم الدفع</label>
                </div>

                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" name="send_to_whatsapp" id="send_to_whatsapp"
                           <?= ($editMode && $patientToEdit['send_to_whatsapp']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="send_to_whatsapp">إرسال النتائج عبر الواتساب</label>
                </div>

                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" name="send_sms" id="send_sms"
                           <?= ($editMode && $patientToEdit['send_sms']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="send_sms">إرسال رسالة SMS</label>
                </div>

                <div class="mb-3">
                    <label class="form-label">نص رسالة SMS:</label>
                    <textarea class="form-control" name="sms_message" rows="3"><?= $editMode ? htmlspecialchars($patientToEdit['sms_message']) : 'عميلنا العزيز برجاء التوجه الى المعمل لاستلام النتائج أو الدخول الى ال Mobile App الخاص بالمعمل وتسجيل الدخول لرؤية النتائج الخاصه بك' ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100"><i class="fas fa-upload"></i> <?= $editMode ? 'تحديث البيانات' : 'رفع النتائج' ?></button>
                <?php if ($editMode): ?>
                    <a href="upload.php" class="btn btn-secondary btn-lg w-100 mt-2"><i class="fas fa-times"></i> إلغاء التعديل</a>
                <?php endif; ?>
            </form>

            <!-- عرض الرسائل المتعلقة بالنموذج هنا -->
            <?php if (isset($message) && (strpos($message, 'رفع') !== false || strpos($message, 'خطأ') !== false || strpos($message, 'تحديث') !== false)): ?>
                <div class="mt-3">
                    <?= $message ?>
                </div>
            <?php endif; ?>
        </div>

        <hr>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3><i class="fas fa-list"></i> النتائج الحالية:</h3>
            
            <!-- صندوق البحث -->
            <div class="search-box">
                <form action="" method="get" class="form-inline">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" placeholder="ابحث باسم المريض أو رقم الهاتف..." value="<?= htmlspecialchars($searchQuery) ?>">
                        <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> بحث</button>
                        <?php if (!empty($searchQuery)): ?>
                            <a href="upload.php" class="btn btn-secondary"><i class="fas fa-times"></i> إلغاء</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- عرض الرسائل المتعلقة بالنتائج هنا -->
        <?php if (isset($message) && (strpos($message, 'حذف') !== false || strpos($message, 'SMS') !== false)): ?>
            <div class="mb-3">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($filteredPatients)): ?>
            <div class="results-table">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>اسم المريض</th>
                            <th>رقم الهاتف</th>
                            <th>حالة الدفع</th>
                            <th>التواريخ والنتائج</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredPatients as $patient): ?>
                            <tr>
                                <td><?= htmlspecialchars($patient["name"]) ?></td>
                                <td><?= htmlspecialchars($patient["phone"]) ?></td>
                                <td>
                                    <span class="payment-status <?= $patient['is_paid'] ? 'paid' : 'unpaid' ?>">
                                        <?= $patient['is_paid'] ? 'تم الدفع' : 'لم يتم الدفع' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php foreach ($patient["tests"] as $test): ?>
                                        <div class="file-item">
                                            <span class="badge bg-light text-dark"><i class="far fa-calendar-alt"></i> <?= htmlspecialchars($test["date"]) ?></span>
                                            <a href="<?= htmlspecialchars($test["file"]) ?>" target="_blank" class="pdf-link"><i class="fas fa-file-pdf"></i> عرض PDF</a>
                                            <a href="?delete_file=<?= urlencode($test['file']) ?>&phone=<?= urlencode($patient['phone']) ?>" 
                                               class="btn btn-danger btn-sm py-0"
                                               onclick="return confirm('هل أنت متأكد أنك تريد حذف هذا الملف؟')">
                                                <i class="fas fa-trash-alt"></i> حذف
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                <td class="action-buttons">
                                    <div class="d-flex flex-column gap-2">
                                        <a href="?edit_patient=<?= urlencode($patient['phone']) ?>" 
                                           class="btn btn-sm btn-info">
                                            <i class="fas fa-edit"></i> تعديل
                                        </a>
                                        <a href="?toggle_payment=<?= urlencode($patient['phone']) ?>" 
                                           class="btn btn-sm <?= $patient['is_paid'] ? 'btn-warning' : 'btn-success' ?>">
                                            <i class="fas fa-money-bill-wave"></i> <?= $patient['is_paid'] ? 'إلغاء الدفع' : 'تم الدفع' ?>
                                        </a>
                                        <a href="?send_whatsapp=<?= urlencode($patient['phone']) ?>" 
                                           class="btn btn-sm btn-success">
                                            <i class="fab fa-whatsapp"></i> إرسال واتساب
                                        </a>
                                        <a href="?send_sms=<?= urlencode($patient['phone']) ?>" 
                                           class="btn btn-sm btn-info text-white">
                                            <i class="fas fa-sms"></i> إرسال SMS
                                        </a>
                                        <a href="?delete_patient=<?= urlencode($patient['phone']) ?>" 
                                           class="btn btn-danger btn-sm"
                                           onclick="return confirm('هل أنت متأكد أنك تريد حذف هذا المريض وكل تحليلاته؟')">
                                            <i class="fas fa-trash-alt"></i> حذف الكل
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <?= empty($searchQuery) ? 'لا توجد نتائج حتى الآن' : 'لا توجد نتائج مطابقة للبحث' ?>
            </div>
        <?php endif; ?>
    </div>
    <a href="index.html" class="btn btn-primary mt-3 mb-3">العودة إلى صفحة البحث</a>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>