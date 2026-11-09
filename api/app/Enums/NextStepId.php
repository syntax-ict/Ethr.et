<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every step `GET /next-steps` can return.
 *
 * The case order is the tie-break order inside one severity: the steps that
 * hold up other people's work come before configuration, and configuration
 * before housekeeping. The frontend registry is keyed by this enum, which
 * Scramble publishes as a union type, so a case added here without copy there
 * fails the TypeScript build rather than rendering a blank row.
 *
 * Severity is a property of the step, not of a moment, so a step that cannot
 * be dismissed for good (a blocker) is known from its id alone.
 */
enum NextStepId: string
{
    // Blockers: somebody cannot work, or nobody can administer.
    case AttendanceBlockers = 'attendance_blockers';
    case PayrollFailed = 'payroll_failed';
    case SetupAdminPresent = 'setup_admin_present';
    case SetupAdminActive = 'setup_admin_active';
    case OwnerPhone = 'owner_phone';
    case StatutoryOverride = 'statutory_override';

    // Warnings: work waiting for a decision, or setup that is genuinely unfinished.
    case Integrity = 'integrity';
    case PayrollApprove = 'payroll_approve';
    case Approvals = 'approvals';
    case ContractsEnding = 'contracts_ending';
    case SetupWizard = 'setup_wizard';
    case SetupBranch = 'setup_branch';
    case SetupDepartments = 'setup_departments';
    case SetupPositions = 'setup_positions';
    case SetupShifts = 'setup_shifts';
    case SetupLeaveTypes = 'setup_leave_types';
    case SetupHolidays = 'setup_holidays';
    case SetupEmployees = 'setup_employees';
    case SetupEmployeeDepartments = 'setup_employee_departments';
    case SetupAttendanceSource = 'setup_attendance_source';
    case SetupEmployerTin = 'setup_employer_tin';
    case SetupTaxCenter = 'setup_tax_center';
    case SetupPensionRegistration = 'setup_pension_registration';
    case SetupPayoutMethod = 'setup_payout_method';
    case SetupEmployeeSalaries = 'setup_employee_salaries';
    case LeaveBelowLaw = 'leave_below_law';
    case RecordsMissingTin = 'records_missing_tin';
    case RecordsMissingBank = 'records_missing_bank';
    case NationalityReview = 'nationality_review';

    // Information: worth doing, nobody is waiting.
    case CompleteProfile = 'complete_profile';
    case RecordsMissingEmergencyContact = 'records_missing_emergency_contact';
    case RecordsMissingPhone = 'records_missing_phone';
    case UnmatchedBadges = 'unmatched_badges';
    case TeamAbsent = 'team_absent';
    case SetupDeviceLinked = 'setup_device_linked';
    case ProfileLegalReview = 'profile_legal_review';

    public function group(): NextStepGroup
    {
        return match ($this) {
            self::CompleteProfile => NextStepGroup::Profile,
            self::Approvals => NextStepGroup::Approvals,
            self::ContractsEnding,
            self::RecordsMissingTin,
            self::RecordsMissingBank,
            self::RecordsMissingEmergencyContact,
            self::RecordsMissingPhone,
            self::NationalityReview => NextStepGroup::Records,
            self::AttendanceBlockers,
            self::SetupWizard,
            self::SetupBranch,
            self::SetupDepartments,
            self::SetupPositions,
            self::SetupShifts,
            self::SetupLeaveTypes,
            self::SetupHolidays,
            self::SetupEmployees,
            self::SetupEmployeeDepartments,
            self::SetupAttendanceSource,
            self::SetupAdminPresent,
            self::SetupAdminActive,
            self::OwnerPhone,
            self::StatutoryOverride,
            self::SetupEmployerTin,
            self::SetupTaxCenter,
            self::SetupPensionRegistration,
            self::SetupPayoutMethod,
            self::SetupEmployeeSalaries,
            self::LeaveBelowLaw,
            self::SetupDeviceLinked,
            self::ProfileLegalReview => NextStepGroup::Setup,
            self::PayrollFailed,
            self::PayrollApprove,
            self::Integrity,
            self::UnmatchedBadges,
            self::TeamAbsent => NextStepGroup::Operations,
        };
    }

    public function severity(): NextStepSeverity
    {
        return match ($this) {
            self::AttendanceBlockers,
            self::PayrollFailed,
            self::SetupAdminPresent,
            self::SetupAdminActive,
            self::OwnerPhone,
            self::StatutoryOverride => NextStepSeverity::Blocker,
            self::CompleteProfile,
            self::RecordsMissingEmergencyContact,
            self::RecordsMissingPhone,
            self::UnmatchedBadges,
            self::TeamAbsent,
            self::SetupDeviceLinked,
            self::ProfileLegalReview => NextStepSeverity::Info,
            default => NextStepSeverity::Warning,
        };
    }

    /** Position in the enum: the tie-break after severity. */
    public function order(): int
    {
        return array_search($this, self::cases(), true);
    }

    /**
     * The step a failing `ReadinessScorer` check becomes. A check the scorer
     * grows that is not mapped here simply does not surface, rather than
     * surfacing with copy nobody wrote. The scorer no longer has an
     * `admin_present` check (an active administrator implies one), so
     * SetupAdminPresent is never produced; the case stays because stored
     * dismissals may name it.
     */
    public static function fromReadinessCheck(string $check): ?self
    {
        return match ($check) {
            'branch_exists' => self::SetupBranch,
            'departments_exist' => self::SetupDepartments,
            'positions_exist' => self::SetupPositions,
            'shifts_exist' => self::SetupShifts,
            'leave_types_exist' => self::SetupLeaveTypes,
            'holidays_exist' => self::SetupHolidays,
            'has_employees' => self::SetupEmployees,
            'employees_have_department' => self::SetupEmployeeDepartments,
            'attendance_source_configured' => self::SetupAttendanceSource,
            'active_admin' => self::SetupAdminActive,
            'employer_tin_set' => self::SetupEmployerTin,
            'tax_center_set' => self::SetupTaxCenter,
            'pension_registration_set' => self::SetupPensionRegistration,
            'payout_method_set' => self::SetupPayoutMethod,
            'employees_have_salary' => self::SetupEmployeeSalaries,
            'statutory_law_followed' => self::StatutoryOverride,
            'leave_types_meet_law' => self::LeaveBelowLaw,
            'device_linked' => self::SetupDeviceLinked,
            default => null,
        };
    }
}
