"""Company-wide payroll percentages, stored in `settings` under payroll.rates.*."""

from core.models import Setting

DEFAULTS = {
    "basic_cutoff_percent": 50.0,   # % of monthly salary paid per semi-monthly cutoff
    "working_days_per_month": 22,   # daily rate = monthly / this
    "hours_per_day": 8,             # hourly rate = daily / this
    "allowance_percent": 0.0,       # % of basic pay added as allowance for everyone
    "ot_regular_percent": 125.0,    # OT premium as % of the hourly rate
    "ot_rest_day_percent": 130.0,
    "ot_holiday_percent": 200.0,
    "withholding_tax_percent": 0.0, # flat % of taxable pay (gross − SSS/PhilHealth/Pag-IBIG)
    "pagibig_monthly_cap": 200.0,   # ₱ cap on the employee's monthly Pag-IBIG share
}

LABELS = {
    "basic_cutoff_percent": "Basic pay per cutoff",
    "working_days_per_month": "Working days per month",
    "hours_per_day": "Hours per day",
    "allowance_percent": "Allowance (of basic pay)",
    "ot_regular_percent": "Regular overtime",
    "ot_rest_day_percent": "Rest-day overtime",
    "ot_holiday_percent": "Holiday overtime",
    "withholding_tax_percent": "Withholding tax",
    "pagibig_monthly_cap": "Pag-IBIG monthly cap (₱)",
}


def get(key: str) -> float:
    return float(Setting.get(f"payroll.rates.{key}", DEFAULTS[key]))


def all_rates() -> dict:
    return {k: get(k) for k in DEFAULTS}


def set_rates(values: dict):
    for key in DEFAULTS:
        if key in values:
            Setting.set(f"payroll.rates.{key}", float(values[key]))


def ot_multiplier(kind: str) -> float:
    key = {"rest_day": "ot_rest_day_percent", "holiday": "ot_holiday_percent"}.get(kind, "ot_regular_percent")
    return get(key) / 100
