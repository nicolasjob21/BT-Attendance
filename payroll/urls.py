from django.urls import path

from . import views

urlpatterns = [
    path("payroll", views.index, name="payroll.index"),
    path("payroll/periods", views.create_period, name="payroll.periods.create"),
    path("payroll/<int:pk>/generate", views.generate, name="payroll.generate"),
    path("payroll/<int:pk>/release", views.release, name="payroll.release"),
    path("payroll/<int:pk>/close", views.close, name="payroll.close"),
    path("payroll/<int:pk>/export", views.export, name="payroll.export"),
    path("payroll/<int:pk>/print", views.print_batch, name="payroll.print"),
    path("payroll/item/<int:pk>", views.show, name="payroll.show"),
    path("payroll/lines/<int:pk>/edit", views.edit_line, name="payroll.lines.edit"),
    path("payroll/lines/<int:pk>", views.update_line, name="payroll.lines.update"),
    path("payroll/lines/<int:pk>/reset", views.reset_line, name="payroll.lines.reset"),
    path("payroll/rates", views.rates_index, name="payroll.rates"),
    path("payroll/rates/update", views.rates_update, name="payroll.rates.update"),
    path("payroll/deductions", views.deductions_index, name="payroll.deductions"),
    path("payroll/deductions/store", views.deductions_store, name="payroll.deductions.store"),
    path("payroll/deductions/<int:pk>", views.deductions_update, name="payroll.deductions.update"),
    path("payroll/deductions/<int:pk>/cancel", views.deductions_cancel, name="payroll.deductions.cancel"),
    path("my-payslips", views.mine, name="payroll.mine"),
    path("employees/<int:pk>/salary-history", views.salary_history, name="employees.salary-history"),
]
