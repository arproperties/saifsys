<?php
/**
 * Creating the `user` account behind an employee.
 *
 * Two places need this and they must not drift: the Create Login button on the
 * profile, and setting a field app PIN — which creates the account on the
 * employee's behalf, because an office asked to "make a PIN" should not first
 * have to know that jobs are assigned to a user row rather than to an employee
 * row.
 *
 * Extracted verbatim from hr/user_create_for_employee.php, which now calls it.
 */

if (!function_exists('hr_login_ascii_slug_base')) {
    function hr_login_ascii_slug_base(string $s): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        $s = strtolower($s);
        return preg_replace('/[^a-z0-9]+/', '', $s) ?: 'user';
    }
}

if (!function_exists('hr_login_make_username')) {
    function hr_login_make_username(PDO $conn, string $full_name, string $emp_code, int $maxLen = 20): string
    {
        $base = '';
        $parts = preg_split('/\s+/', trim($full_name ?? ''));
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
        if (count($parts) >= 2) {
            $base = hr_login_ascii_slug_base($parts[0]) . hr_login_ascii_slug_base(end($parts));
        } else {
            $base = hr_login_ascii_slug_base($full_name ?: $emp_code);
        }
        $base = substr($base, 0, $maxLen);

        $check = $conn->prepare("SELECT COUNT(*) FROM `user` WHERE username=?");
        $name = $base ?: 'user';
        $i = 1;
        while (true) {
            $check->execute([$name]);
            if ($check->fetchColumn() == 0) {
                break;
            }
            $suffix = "-$i";
            $name = substr($base, 0, $maxLen - strlen($suffix)) . $suffix;
            $i++;
            if ($i > 99) {
                $name = substr(($base ?: 'user') . uniqid('', true), 0, $maxLen);
                break;
            }
        }
        return $name;
    }
}

if (!function_exists('hr_login_random_password')) {
    function hr_login_random_password(int $len = 10): string
    {
        // No 0/O/1/l/I: these get read aloud and written on paper.
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $pw = '';
        for ($i = 0; $i < $len; $i++) {
            $pw .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pw;
    }
}

if (!function_exists('hr_login_roles_for_employee')) {
    /**
     * Default roles from department & position.
     * Edit the mappings below to fit your business terminology.
     */
    function hr_login_roles_for_employee(array $emp): array
    {
        $roles = [];

        $dept = strtolower(trim($emp['dept_name'] ?? ''));
        $pos  = strtolower(trim($emp['position_title'] ?? ''));

        // Map departments -> role
        $mapDept = [
            'cleaners'     => 'Cleaner',
            'field'        => 'Cleaner',
            'drivers'      => 'Driver',
            'transport'    => 'Driver',
            'hr'           => 'HR',
            'human'        => 'HR',
            'accounts'     => 'Accountant',
            'accounting'   => 'Accountant',
            'operations'   => 'Dispatcher',
            'dispatch'     => 'Dispatcher',
            'admin'        => 'Admin',
            'management'   => 'Admin',
        ];
        foreach ($mapDept as $needle => $role) {
            if ($dept !== '' && str_contains($dept, $needle)) {
                $roles[] = $role;
            }
        }

        // Map position keywords -> role
        $mapPos = [
            'driver'      => 'Driver',
            'clean'       => 'Cleaner',
            'housekeep'   => 'Cleaner',
            'account'     => 'Accountant',
            'finance'     => 'Accountant',
            'hr'          => 'HR',
            'recruit'     => 'HR',
            'dispatch'    => 'Dispatcher',
            'coordinator' => 'Dispatcher',
            'manager'     => 'Admin',   // up-level managers get Admin; tweak if needed
            'supervisor'  => 'Admin',
        ];
        foreach ($mapPos as $needle => $role) {
            if ($pos !== '' && str_contains($pos, $needle)) {
                $roles[] = $role;
            }
        }

        // Fallback if nothing matched
        if (!$roles) {
            $roles[] = 'Viewer';
        }

        return array_values(array_unique($roles));
    }
}

if (!function_exists('hr_employee_has_login')) {
    /**
     * Does this employee have a login that actually exists?
     *
     * `employees.user_id` has no foreign key behind it and some rows point at a
     * `user` row that was deleted years ago. A dangling link is not a login: it
     * fails every insert that references it, so it must read as "none" here or
     * the caller will refuse to create the account that would fix it.
     */
    function hr_employee_has_login(PDO $conn, ?int $userId): bool
    {
        if (!$userId) {
            return false;
        }
        $stmt = $conn->prepare("SELECT 1 FROM `user` WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('hr_create_login_for_employee')) {
    /**
     * Create the `user` account for an employee, link it both ways, and give it
     * roles derived from their department and position.
     *
     * The caller is responsible for the audit entry and for telling somebody the
     * password — it is returned once here and never recoverable afterwards.
     *
     * @param array $emp needs id, full_name, employee_code, email, phone,
     *                   address, position_title, dept_name and company_id
     * @return array{user_id:int,username:string,password:string,roles:string[]}
     */
    function hr_create_login_for_employee(PDO $conn, array $emp, ?int $creatorId): array
    {
        $empId = (int)$emp['id'];
        $username = hr_login_make_username(
            $conn,
            (string)($emp['full_name'] ?? ''),
            (string)($emp['employee_code'] ?? ''),
            20
        );
        $tempPassword = hr_login_random_password(10);

        // Legacy auth: md5 is what login.php still accepts and silently
        // upgrades to bcrypt on the person's first successful sign-in.
        $ins = $conn->prepare("
            INSERT INTO `user` (username, fullname, email, contactnumber, salary, address, password, status, date, creatorid)
            VALUES (?, ?, ?, ?, NULL, ?, ?, 1, CURDATE(), ?)
        ");
        $ins->execute([
            $username,
            $emp['full_name'] ?: $emp['employee_code'],
            $emp['email'] ?? null,
            $emp['phone'] ?? null,
            $emp['address'] ?? null,
            md5($tempPassword),
            $creatorId,
        ]);
        $newUserId = (int)$conn->lastInsertId();

        /* Link user to employee, both directions. */
        $conn->prepare("UPDATE employees SET user_id=? WHERE id=?")->execute([$newUserId, $empId]);
        $conn->prepare("UPDATE `user` SET employee_id=? WHERE id=?")->execute([$empId, $newUserId]);

        /* Put them in their employer's company.
           Job lists are scoped by user_companies, and the field app refuses to
           sign anyone in who is in no company at all — so without this row a
           freshly created login gets "that PIN did not work" with no way to
           tell why. */
        if (!empty($emp['company_id'])) {
            $conn->prepare("
                INSERT INTO user_companies (user_id, company_id, is_primary, created_at)
                VALUES (?, ?, 1, NOW())
            ")->execute([$newUserId, (int)$emp['company_id']]);
        }

        /* Assign roles dynamically */
        $roleNames  = hr_login_roles_for_employee($emp);
        $getRoleId  = $conn->prepare("SELECT id FROM roles WHERE name=?");
        $createRole = $conn->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");

        foreach ($roleNames as $rn) {
            $getRoleId->execute([$rn]);
            $rid = (int)$getRoleId->fetchColumn();
            if (!$rid) {
                $createRole->execute([$rn, "Auto-created from department/position"]);
                $rid = (int)$conn->lastInsertId();
            }
            $exists = $conn->prepare("SELECT 1 FROM user_roles WHERE user_id=? AND role_id=?");
            $exists->execute([$newUserId, $rid]);
            if (!$exists->fetchColumn()) {
                $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")
                     ->execute([$newUserId, $rid]);
            }
        }

        return [
            'user_id'  => $newUserId,
            'username' => $username,
            'password' => $tempPassword,
            'roles'    => $roleNames,
        ];
    }
}
