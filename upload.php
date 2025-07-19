<?php
// تفعيل التقارير عن الأخطاء
error_reporting(E_ALL);
ini_set('display_errors', 1);

// دالة لتسجيل الأخطاء
function logError($message) {
    $logFile = 'error_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

$dataFile = "https://el-eman-lab-obour-city1.github.io/results/data.json";
$uploadDir = "uploads/";

// تحميل بيانات المرضى مع معالجة الأخطاء
if (file_exists($dataFile)) {
    $content = file_get_contents($dataFile);
    if ($content === false) {
        die(json_encode(['status' => 'error', 'message' => '❌ لا يمكن قراءة ملف البيانات']));
    }
    $patients = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die(json_encode(['status' => 'error', 'message' => '❌ ملف البيانات تالف: '.json_last_error_msg()]));
    }
    
    // فرز المرضى حسب تاريخ الإضافة (الأحدث أولاً)
    usort($patients, function($a, $b) {
        $dateA = isset($a['added_date']) ? strtotime($a['added_date']) : 0;
        $dateB = isset($b['added_date']) ? strtotime($b['added_date']) : 0;
        return $dateB - $dateA;
    });
} else {
    $patients = [];
}

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
    try {
        $phoneToDelete = $_GET['delete_patient'];
        $found = false;
        
        foreach ($patients as $index => $patient) {
            if ($patient['phone'] === $phoneToDelete) {
                // حذف ملفات PDF المرتبطة بالمريض أولاً
                foreach ($patient['tests'] as $test) {
                    if (file_exists($test['file'])) {
                        if (!unlink($test['file'])) {
                            logError("Failed to delete file: " . $test['file']);
                        }
                    }
                }
                // حذف المريض من المصفوفة
                unset($patients[$index]);
                $found = true;
                break;
            }
        }
        
        if ($found) {
            // إعادة ترقيم المصفوفة
            $patients = array_values($patients);
            // حفظ التغييرات
            if (file_put_contents($dataFile, json_encode($patients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                echo json_encode(['status' => 'success', 'message' => '✅ تم حذف المريض بنجاح']);
            } else {
                logError("Failed to save data after deletion");
                echo json_encode(['status' => 'error', 'message' => '❌ فشل في حفظ البيانات بعد الحذف']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => '❌ لم يتم العثور على المريض']);
        }
        exit();
    } catch (Exception $e) {
        logError("Delete patient error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '❌ حدث خطأ أثناء حذف المريض']);
        exit();
    }
}

// معالجة طلب حذف ملف معين
if (isset($_GET['delete_file'])) {
    try {
        $phone = $_GET['phone'];
        $fileToDelete = $_GET['delete_file'];
        $found = false;
        
        foreach ($patients as &$patient) {
            if ($patient['phone'] === $phone) {
                foreach ($patient['tests'] as $index => $test) {
                    if ($test['file'] === $fileToDelete) {
                        // حذف الملف الفعلي من السيرفر
                        if (file_exists($fileToDelete)) {
                            if (!unlink($fileToDelete)) {
                                logError("Failed to delete file: " . $fileToDelete);
                            }
                        }
                        // حذف الملف من مصفوفة الاختبارات
                        unset($patient['tests'][$index]);
                        // إعادة ترقيم المصفوفة
                        $patient['tests'] = array_values($patient['tests']);
                        $found = true;
                        break 2;
                    }
                }
            }
        }
        
        if ($found) {
            // حفظ التغييرات
            if (file_put_contents($dataFile, json_encode($patients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                echo json_encode(['status' => 'success', 'message' => '✅ تم حذف الملف بنجاح']);
            } else {
                logError("Failed to save data after file deletion");
                echo json_encode(['status' => 'error', 'message' => '❌ فشل في حفظ البيانات بعد حذف الملف']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => '❌ لم يتم العثور على الملف']);
        }
        exit();
    } catch (Exception $e) {
        logError("Delete file error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '❌ حدث خطأ أثناء حذف الملف']);
        exit();
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
    try {
        $phone = $_GET['toggle_payment'];
        $found = false;
        
        foreach ($patients as &$patient) {
            if ($patient['phone'] === $phone) {
                $patient['is_paid'] = !$patient['is_paid'];
                $newStatus = $patient['is_paid'];
                $found = true;
                break;
            }
        }
        
        if ($found) {
            if (file_put_contents($dataFile, json_encode($patients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                echo json_encode([
                    'status' => 'success', 
                    'message' => '✅ تم تحديث حالة الدفع',
                    'is_paid' => $newStatus,
                    'new_text' => $newStatus ? 'إلغاء الدفع' : 'تم الدفع',
                    'new_class' => $newStatus ? 'btn-warning' : 'btn-success'
                ]);
            } else {
                logError("Failed to save payment status");
                echo json_encode(['status' => 'error', 'message' => '❌ فشل في حفظ حالة الدفع']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => '❌ لم يتم العثور على المريض']);
        }
        exit();
    } catch (Exception $e) {
        logError("Toggle payment error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '❌ حدث خطأ أثناء تحديث حالة الدفع']);
        exit();
    }
}

// دالة لإضافة رمز الدولة إذا لم يكن موجوداً
function formatPhoneNumber($phone) {
    try {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (empty($phone)) {
            return '';
        }
        
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
    } catch (Exception $e) {
        logError("Format phone error: " . $e->getMessage());
        return $phone;
    }
}

// معالجة طلب إرسال عبر الواتساب
if (isset($_GET['send_whatsapp'])) {
    try {
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
            echo json_encode(['status' => 'redirect', 'url' => $whatsappUrl]);
        } else {
            echo json_encode(['status' => 'error', 'message' => '❌ لم يتم العثور على المريض']);
        }
        exit();
    } catch (Exception $e) {
        logError("Whatsapp send error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '❌ حدث خطأ أثناء إرسال واتساب']);
        exit();
    }
}

// معالجة طلب إرسال SMS
if (isset($_GET['send_sms'])) {
    try {
        $phone = $_GET['send_sms'];
        $patient = null;
        $found = false;
        
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
                    $found = true;
                    break;
                }
            }
            
            if ($found) {
                if (file_put_contents($dataFile, json_encode($patients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                    echo json_encode(['status' => 'success', 'message' => '✅ تم إعداد رسالة SMS للارسال: ' . $smsMessage]);
                } else {
                    logError("Failed to save SMS data");
                    echo json_encode(['status' => 'error', 'message' => '❌ فشل في حفظ بيانات SMS']);
                }
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => '❌ لم يتم العثور على المريض']);
        }
        exit();
    } catch (Exception $e) {
        logError("SMS send error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '❌ حدث خطأ أثناء إرسال SMS']);
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // التحقق من وجود مجلد التحميل
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                echo json_encode(['status' => 'error', 'message' => '❌ لا يمكن إنشاء مجلد التحميل']);
                exit();
            }
        }

        if (!is_writable($uploadDir)) {
            echo json_encode(['status' => 'error', 'message' => '❌ مجلد التحميل غير قابل للكتابة']);
            exit();
        }

        $isPaid = isset($_POST["is_paid"]) ? (bool)$_POST["is_paid"] : false;
        $sendToWhatsapp = isset($_POST["send_to_whatsapp"]) ? true : false;
        $sendSMS = isset($_POST["send_sms"]) ? true : false;
        $smsMessage = isset($_POST["sms_message"]) ? trim($_POST["sms_message"]) : '';

        $patientName = isset($_POST["patient_name"]) ? trim($_POST["patient_name"]) : '';
        $phone = isset($_POST["phone"]) ? trim($_POST["phone"]) : '';
        $testDate = isset($_POST["test_date"]) ? $_POST["test_date"] : date('Y-m-d');

        if (empty($patientName) || empty($phone)) {
            echo json_encode(['status' => 'error', 'message' => '❌ اسم المريض ورقم الهاتف مطلوبان']);
            exit();
        }

        $files = $_FILES["pdf_files"];
        $uploadSuccess = true;
        $uploadedFiles = [];

        // معالجة الملفات المرفوعة
        if (isset($files['name']) && is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    $errorMsg = getUploadErrorMsg($files['error'][$i]);
                    echo json_encode(['status' => 'error', 'message' => '❌ ' . $errorMsg]);
                    exit();
                }

                $fileType = strtolower(pathinfo($files["name"][$i], PATHINFO_EXTENSION));
                if ($fileType !== "pdf") {
                    echo json_encode(['status' => 'error', 'message' => '❌ الملف يجب أن يكون PDF فقط']);
                    exit();
                }

                $newFileName = preg_replace('/\s+/', '_', $patientName) . "_" . time() . "_" . $i . ".pdf";
                $destination = $uploadDir . $newFileName;

                if (!move_uploaded_file($files["tmp_name"][$i], $destination)) {
                    $error = error_get_last();
                    logError("Failed to move uploaded file: " . ($error ? $error['message'] : 'Unknown error'));
                    echo json_encode(['status' => 'error', 'message' => '❌ فشل في رفع الملف']);
                    exit();
                }

                $uploadedFiles[] = [
                    "date" => $testDate,
                    "file" => $destination
                ];
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
                    "tests" => $uploadedFiles,
                    "added_date" => date('Y-m-d H:i:s') // إضافة تاريخ الإضافة
                ];
            }

            if (file_put_contents($dataFile, json_encode($patients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                $response = ['status' => 'success', 'message' => '✅ تم رفع الملفات وتحديث البيانات'];
                
                if ($sendToWhatsapp) {
                    $whatsappNumber = formatPhoneNumber($phone);
                    $message = "عميلنا العزيز " . $patientName . "، نتائج تحاليلك جاهزة. يمكنك الاطلاع عليها عبر التطبيق أو زيارة المعمل.";
                    $whatsappUrl = "https://wa.me/$whatsappNumber?text=" . urlencode($message);
                    $response['redirect'] = $whatsappUrl;
                }
                
                echo json_encode($response);
            } else {
                logError("Failed to save patient data");
                echo json_encode(['status' => 'error', 'message' => '❌ فشل في حفظ بيانات المريض']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => '❌ لم يتم رفع أي ملفات']);
        }
        exit();
    } catch (Exception $e) {
        logError("Form submission error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '❌ حدث خطأ أثناء معالجة البيانات']);
        exit();
    }
}

// دالة للحصول على رسالة خطأ الرفع
function getUploadErrorMsg($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return 'حجم الملف يتجاوز الحد المسموح به في السيرفر';
        case UPLOAD_ERR_FORM_SIZE:
            return 'حجم الملف يتجاوز الحد المسموح به في النموذج';
        case UPLOAD_ERR_PARTIAL:
            return 'تم رفع جزء من الملف فقط';
        case UPLOAD_ERR_NO_FILE:
            return 'لم يتم رفع أي ملف';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'المجلد المؤقت غير موجود';
        case UPLOAD_ERR_CANT_WRITE:
            return 'فشل في كتابة الملف على القرص';
        case UPLOAD_ERR_EXTENSION:
            return 'رفع الملف توقف بواسطة إضافة';
        default:
            return 'خطأ غير معروف أثناء رفع الملف';
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            height: 150px;
            width: auto;
        }
        .mb-3 form-check{
        }
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap');
        
        /* رسائل الـ Toast */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1100;
        }
        .toast {
            transition: all 0.3s ease;
        }
    </style>
</head>

<body>
    <div class="toast-container"></div>

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
            <form id="uploadForm" action="" method="post" enctype="multipart/form-data">
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
                    <input type="date" class="form-control form-control-lg" name="test_date" required 
                           value="<?= $editMode ? htmlspecialchars($patientToEdit['tests'][0]['date'] ?? date('Y-m-d')) : date('Y-m-d') ?>">
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


                <button type="submit" class="btn btn-primary btn-lg w-100"><i class="fas fa-upload"></i> <?= $editMode ? 'تحديث البيانات' : 'رفع النتائج' ?></button>
                <?php if ($editMode): ?>
                    <a href="upload.php" class="btn btn-secondary btn-lg w-100 mt-2"><i class="fas fa-times"></i> إلغاء التعديل</a>
                <?php endif; ?>
            </form>
        </div>

        <hr>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3><i class="fas fa-list"></i> النتائج الحالية:</h3>
            
            <!-- صندوق البحث -->
            <div class="search-box">
                <form id="searchForm" action="" method="get" class="form-inline">
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
                            <tr data-phone="<?= htmlspecialchars($patient["phone"]) ?>">
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
                                            <button class="btn btn-danger btn-sm py-0 delete-file-btn" 
                                               data-file="<?= htmlspecialchars($test['file']) ?>" 
                                               data-phone="<?= htmlspecialchars($patient['phone']) ?>">
                                                <i class="fas fa-trash-alt"></i> حذف
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                <td class="action-buttons">
                                    <div class="d-flex flex-column gap-2">
                                         <button class="btn btn-sm btn-success send-whatsapp-btn"
                                                data-phone="<?= htmlspecialchars($patient['phone']) ?>">
                                            <i class="fab fa-whatsapp"></i> إرسال واتساب
                                        </button>
                                        <a href="?edit_patient=<?= urlencode($patient['phone']) ?>" 
                                           class="btn btn-sm btn-info">
                                            <i class="fas fa-edit"></i> تعديل
                                        </a>
                                        
                                        <button class="btn btn-sm <?= $patient['is_paid'] ? 'btn-warning' : 'btn-success' ?> toggle-payment-btn"
                                                data-phone="<?= htmlspecialchars($patient['phone']) ?>">
                                            <i class="fas fa-money-bill-wave"></i> <?= $patient['is_paid'] ? 'إلغاء الدفع' : 'تم الدفع' ?>
                                        </button>
                                       
                                        
                                        <button class="btn btn-danger btn-sm delete-patient-btn"
                                                data-phone="<?= htmlspecialchars($patient['phone']) ?>">
                                            <i class="fas fa-trash-alt"></i> حذف الكل
                                        </button>
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
    <script>
    $(document).ready(function() {
        // دالة لعرض رسائل Toast
        function showToast(message, type = 'success') {
            const toastContainer = $('.toast-container');
            const toastId = 'toast-' + Date.now();
            
            const toast = $(`
                <div class="toast align-items-center text-white bg-${type} border-0" id="${toastId}" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body">
                            ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `);
            
            toastContainer.append(toast);
            
            const bsToast = new bootstrap.Toast(toast[0]);
            bsToast.show();
            
            // إزالة الـ Toast بعد 5 ثواني
            setTimeout(() => {
                toast.remove();
            }, 5000);
        }
        
        // معالجة إرسال النموذج عبر AJAX
        $('#uploadForm').on('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = $(this).find('button[type="submit"]');
            const originalText = submitBtn.html();
            
            submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري المعالجة...');
            
            $.ajax({
                url: 'upload.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    try {
                        const data = JSON.parse(response);
                        if (data.status === 'success') {
                            showToast(data.message);
                            if (data.redirect) {
                                window.open(data.redirect, '_blank');
                            }
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showToast(data.message, 'danger');
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e, response);
                        showToast('❌ خطأ في معالجة الاستجابة: ' + e.message, 'danger');
                    }
                    submitBtn.prop('disabled', false).html(originalText);
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    showToast('❌ خطأ في الاتصال: ' + error, 'danger');
                    submitBtn.prop('disabled', false).html(originalText);
                }
            });
        });
        
        // معالجة البحث عبر AJAX
        $('#searchForm').on('submit', function(e) {
            e.preventDefault();
            const formData = $(this).serialize();
            const searchBtn = $(this).find('button[type="submit"]');
            const originalText = searchBtn.html();
            
            searchBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري البحث...');
            
            $.ajax({
                url: 'upload.php',
                type: 'GET',
                data: formData,
                success: function(response) {
                    // في حالة البحث، نعيد تحميل الصفحة كاملة للتبسيط
                    window.location.href = 'upload.php?' + formData;
                },
                error: function(xhr, status, error) {
                    console.error('Search Error:', status, error);
                    showToast('❌ خطأ في البحث: ' + error, 'danger');
                    searchBtn.prop('disabled', false).html(originalText);
                }
            });
        });
        
        // معالجة حذف المريض
        $('.delete-patient-btn').on('click', function() {
            const phone = $(this).data('phone');
            const btn = $(this);
            const originalText = btn.html();
            
            if (!confirm('هل أنت متأكد أنك تريد حذف هذا المريض وكل تحليلاته؟')) return;
            
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الحذف...');
            
            $.ajax({
                url: 'upload.php?delete_patient=' + encodeURIComponent(phone),
                type: 'GET',
                success: function(response) {
                    try {
                        const data = JSON.parse(response);
                        if (data.status === 'success') {
                            showToast(data.message);
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showToast(data.message, 'danger');
                            btn.prop('disabled', false).html(originalText);
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e, response);
                        showToast('❌ خطأ في معالجة الاستجابة', 'danger');
                        btn.prop('disabled', false).html(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Delete Error:', status, error);
                    showToast('❌ خطأ في الحذف: ' + error, 'danger');
                    btn.prop('disabled', false).html(originalText);
                }
            });
        });
        
        // معالجة حذف ملف معين
        $('.delete-file-btn').on('click', function() {
            const file = $(this).data('file');
            const phone = $(this).data('phone');
            const btn = $(this);
            const originalText = btn.html();
            
            if (!confirm('هل أنت متأكد أنك تريد حذف هذا الملف؟')) return;
            
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الحذف...');
            
            $.ajax({
                url: 'upload.php?delete_file=' + encodeURIComponent(file) + '&phone=' + encodeURIComponent(phone),
                type: 'GET',
                success: function(response) {
                    try {
                        const data = JSON.parse(response);
                        if (data.status === 'success') {
                            showToast(data.message);
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showToast(data.message, 'danger');
                            btn.prop('disabled', false).html(originalText);
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e, response);
                        showToast('❌ خطأ في معالجة الاستجابة', 'danger');
                        btn.prop('disabled', false).html(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Delete File Error:', status, error);
                    showToast('❌ خطأ في حذف الملف: ' + error, 'danger');
                    btn.prop('disabled', false).html(originalText);
                }
            });
        });
        
        // معالجة تغيير حالة الدفع
        $('.toggle-payment-btn').on('click', function() {
            const phone = $(this).data('phone');
            const btn = $(this);
            const originalText = btn.html();
            
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري التحديث...');
            
            $.ajax({
                url: 'upload.php?toggle_payment=' + encodeURIComponent(phone),
                type: 'GET',
                success: function(response) {
                    try {
                        const data = JSON.parse(response);
                        if (data.status === 'success') {
                            showToast(data.message);
                            // تحديث الزر مباشرة دون إعادة تحميل الصفحة
                            btn.toggleClass('btn-success btn-warning');
                            btn.html(`<i class="fas fa-money-bill-wave"></i> ${data.new_text}`);
                            
                            // تحديث حالة الدفع في الجدول
                            $(`tr[data-phone="${phone}"] .payment-status`)
                                .toggleClass('paid unpaid')
                                .text(data.is_paid ? 'تم الدفع' : 'لم يتم الدفع');
                        } else {
                            showToast(data.message, 'danger');
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e, response);
                        showToast('❌ خطأ في معالجة الاستجابة', 'danger');
                    }
                    btn.prop('disabled', false);
                },
                error: function(xhr, status, error) {
                    console.error('Toggle Payment Error:', status, error);
                    showToast('❌ خطأ في تحديث حالة الدفع: ' + error, 'danger');
                    btn.prop('disabled', false).html(originalText);
                }
            });
        });
        
        // معالجة إرسال واتساب
        $('.send-whatsapp-btn').on('click', function() {
            const phone = $(this).data('phone');
            const btn = $(this);
            const originalText = btn.html();
            
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...');
            
            $.ajax({
                url: 'upload.php?send_whatsapp=' + encodeURIComponent(phone),
                type: 'GET',
                success: function(response) {
                    try {
                        const data = JSON.parse(response);
                        if (data.status === 'redirect') {
                            window.open(data.url, '_blank');
                        } else {
                            showToast(data.message, 'danger');
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e, response);
                        showToast('❌ خطأ في معالجة الاستجابة', 'danger');
                    }
                    btn.prop('disabled', false).html(originalText);
                },
                error: function(xhr, status, error) {
                    console.error('Whatsapp Error:', status, error);
                    showToast('❌ خطأ في إرسال واتساب: ' + error, 'danger');
                    btn.prop('disabled', false).html(originalText);
                }
            });
        });
        
        // معالجة إرسال SMS
        $('.send-sms-btn').on('click', function() {
            const phone = $(this).data('phone');
            const btn = $(this);
            const originalText = btn.html();
            
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...');
            
            $.ajax({
                url: 'upload.php?send_sms=' + encodeURIComponent(phone),
                type: 'GET',
                success: function(response) {
                    try {
                        const data = JSON.parse(response);
                        if (data.status === 'success') {
                            showToast(data.message);
                        } else {
                            showToast(data.message, 'danger');
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e, response);
                        showToast('❌ خطأ في معالجة الاستجابة', 'danger');
                    }
                    btn.prop('disabled', false).html(originalText);
                },
                error: function(xhr, status, error) {
                    console.error('SMS Error:', status, error);
                    showToast('❌ خطأ في إرسال SMS: ' + error, 'danger');
                    btn.prop('disabled', false).html(originalText);
                }
            });
        });
    });
    </script>
</body>
</html>
