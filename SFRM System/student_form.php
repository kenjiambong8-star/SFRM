<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/audit.php';
require_login();

$pdo  = db();
$user = current_user();

$id      = (int)str_param($_GET, 'id');
$editing = $id > 0;

$student = [
    'student_no' => '', 'first_name' => '', 'last_name' => '', 'program' => '',
    'year_level' => '1', 'email' => '', 'contact_no' => '', 'status' => 'enrolled',
];

if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'That student was not found.');
        redirect('students.php');
    }
    $student = array_merge($student, array_map(static fn($v) => (string)($v ?? ''), $row));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors['form'] = 'Your page expired. Please try again.';
    } else {
        $in = [
            'student_no' => trim(str_param($_POST, 'student_no')),
            'first_name' => trim(str_param($_POST, 'first_name')),
            'last_name'  => trim(str_param($_POST, 'last_name')),
            'program'    => trim(str_param($_POST, 'program')),
            'year_level' => trim(str_param($_POST, 'year_level')),
            'email'      => trim(str_param($_POST, 'email')),
            'contact_no' => trim(str_param($_POST, 'contact_no')),
            'status'     => trim(str_param($_POST, 'status')),
        ];
        $student = array_merge($student, $in);   // keep what the user typed

        if ($in['student_no'] === '') {
            $errors['student_no'] = 'Student number is required.';
        } elseif (!preg_match('/^[A-Za-z0-9-]{3,20}$/', $in['student_no'])) {
            $errors['student_no'] = 'Use 3 to 20 letters, numbers, or dashes.';
        }
        if ($in['first_name'] === '' || mb_strlen($in['first_name']) > 60) {
            $errors['first_name'] = 'First name is required (60 characters or less).';
        }
        if ($in['last_name'] === '' || mb_strlen($in['last_name']) > 60) {
            $errors['last_name'] = 'Last name is required (60 characters or less).';
        }
        if ($in['program'] === '' || mb_strlen($in['program']) > 100) {
            $errors['program'] = 'Program is required (100 characters or less).';
        }
        if (!preg_match('/^[1-6]$/', $in['year_level'])) {
            $errors['year_level'] = 'Choose a year level from 1 to 6.';
        }
        if ($in['email'] !== '' && (strlen($in['email']) > 120 || !filter_var($in['email'], FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($in['contact_no'] !== '' && !preg_match('/^[0-9+() -]{7,20}$/', $in['contact_no'])) {
            $errors['contact_no'] = 'Enter a valid contact number.';
        }
        if (!in_array($in['status'], ['enrolled', 'graduated', 'dropped'], true)) {
            $errors['status'] = 'Choose a status.';
        }

        if (!$errors) {
            $email   = $in['email'] !== '' ? $in['email'] : null;
            $contact = $in['contact_no'] !== '' ? $in['contact_no'] : null;
            try {
                if ($editing) {
                    $pdo->prepare(
                        'UPDATE students SET student_no = ?, first_name = ?, last_name = ?, program = ?,
                                year_level = ?, email = ?, contact_no = ?, status = ? WHERE id = ?'
                    )->execute([$in['student_no'], $in['first_name'], $in['last_name'], $in['program'],
                                (int)$in['year_level'], $email, $contact, $in['status'], $id]);
                    log_activity((int)$user['id'], $user['username'], 'UPDATE',
                        'Updated student ' . $in['student_no'] . ' - ' . $in['last_name'] . ', ' . $in['first_name']);
                    flash_set('success', 'Student updated.');
                } else {
                    $pdo->prepare(
                        'INSERT INTO students (student_no, first_name, last_name, program, year_level, email, contact_no, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$in['student_no'], $in['first_name'], $in['last_name'], $in['program'],
                                (int)$in['year_level'], $email, $contact, $in['status']]);
                    log_activity((int)$user['id'], $user['username'], 'CREATE',
                        'Added student ' . $in['student_no'] . ' - ' . $in['last_name'] . ', ' . $in['first_name']);
                    flash_set('success', 'Student added.');
                }
                redirect('students.php');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors['student_no'] = 'This student number is already in use.';
                } else {
                    throw $e;
                }
            }
        }
    }
}

$programs = $pdo->query('SELECT DISTINCT program FROM students ORDER BY program')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle  = $editing ? 'Edit student' : 'Add student';
$activePage = 'students';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1><?= e($pageTitle) ?></h1>
  <a href="students.php" class="back-link">Back to students</a>
</div>

<form class="card form-card" method="post" action="student_form.php<?= $editing ? '?id=' . $id : '' ?>" novalidate>
  <?= csrf_field() ?>
  <?php if (isset($errors['form'])): ?><div class="flash flash-error"><?= e($errors['form']) ?></div><?php endif; ?>

  <div class="form-grid">
    <div class="field">
      <label for="student_no">Student number</label>
      <input id="student_no" name="student_no" type="text" maxlength="20" required value="<?= e($student['student_no']) ?>">
      <?php if (isset($errors['student_no'])): ?><p class="field-error"><?= e($errors['student_no']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="status">Status</label>
      <select id="status" name="status">
        <?php foreach (['enrolled', 'graduated', 'dropped'] as $st): ?>
          <option value="<?= e($st) ?>" <?= $student['status'] === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['status'])): ?><p class="field-error"><?= e($errors['status']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="first_name">First name</label>
      <input id="first_name" name="first_name" type="text" maxlength="60" required value="<?= e($student['first_name']) ?>">
      <?php if (isset($errors['first_name'])): ?><p class="field-error"><?= e($errors['first_name']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="last_name">Last name</label>
      <input id="last_name" name="last_name" type="text" maxlength="60" required value="<?= e($student['last_name']) ?>">
      <?php if (isset($errors['last_name'])): ?><p class="field-error"><?= e($errors['last_name']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="program">Program</label>
      <input id="program" name="program" type="text" maxlength="100" list="program-list" required value="<?= e($student['program']) ?>">
      <datalist id="program-list">
        <?php foreach ($programs as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?>
      </datalist>
      <?php if (isset($errors['program'])): ?><p class="field-error"><?= e($errors['program']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="year_level">Year level</label>
      <select id="year_level" name="year_level">
        <?php for ($y = 1; $y <= 6; $y++): ?>
          <option value="<?= $y ?>" <?= (string)$y === $student['year_level'] ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
      <?php if (isset($errors['year_level'])): ?><p class="field-error"><?= e($errors['year_level']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="email">Email (optional)</label>
      <input id="email" name="email" type="email" maxlength="120" value="<?= e($student['email']) ?>">
      <?php if (isset($errors['email'])): ?><p class="field-error"><?= e($errors['email']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="contact_no">Contact number (optional)</label>
      <input id="contact_no" name="contact_no" type="text" maxlength="20" value="<?= e($student['contact_no']) ?>">
      <?php if (isset($errors['contact_no'])): ?><p class="field-error"><?= e($errors['contact_no']) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn-yellow" type="submit"><?= $editing ? 'Save changes' : 'Add student' ?></button>
    <a class="btn-outline" href="students.php">Cancel</a>
  </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
