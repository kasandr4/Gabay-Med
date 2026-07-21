<?php
// admin/hospital-reports.php
// Hospital Reports module - UI only.
// Static placeholder data only; no report generation, database work, SQL, or APIs.

require_once '../includes/auth_guard.php';
require_role('admin');

$today = date("F j, Y");
$current_page = 'reports';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Reports - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../assets/css/hospital-reports.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="page-header reports-page-header">
                <div>
                    <h1>Hospital Reports</h1>
                    <p class="page-subtitle">Generate, monitor, preview, and export hospital-wide reports.</p>
                </div>
                <div class="header-actions reports-header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                    <button class="btn btn-primary reports-action-btn" type="button">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14"></path>
                            <path d="M5 12h14"></path>
                        </svg>
                        Generate Report
                    </button>
                    <button class="btn btn-secondary reports-action-btn" type="button">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <path d="M14 2v6h6"></path>
                            <path d="M9 15h6"></path>
                        </svg>
                        Export PDF
                    </button>
                    <button class="btn btn-secondary reports-action-btn" type="button">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <path d="M7 10l5 5 5-5"></path>
                            <path d="M12 15V3"></path>
                        </svg>
                        Export Excel
                    </button>
                </div>
            </header>

            <section class="stats-grid-6 reports-summary-grid" aria-label="Hospital report summary">
                <div class="stat-card stat-teal reports-stat-card fade-in-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <path d="M14 2v6h6"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value">248</span><span class="stat-label">Total Reports</span><span class="reports-stat-desc">Available across hospital modules</span></div>
                </div>
                <div class="stat-card stat-blue reports-stat-card fade-in-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M8 2v4"></path>
                            <path d="M16 2v4"></path>
                            <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                            <path d="M3 10h18"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value">16</span><span class="stat-label">Reports Generated Today</span><span class="reports-stat-desc">Fresh exports and automated runs</span></div>
                </div>
                <div class="stat-card stat-green reports-stat-card fade-in-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M12 6v6l4 2"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value">32</span><span class="stat-label">Scheduled Reports</span><span class="reports-stat-desc">Recurring report templates</span></div>
                </div>
                <div class="stat-card stat-purple reports-stat-card fade-in-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <path d="M7 10l5 5 5-5"></path>
                            <path d="M12 15V3"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value">Patient Census</span><span class="stat-label">Most Downloaded Report</span><span class="reports-stat-desc">42 downloads this month</span></div>
                </div>
                <div class="stat-card stat-amber reports-stat-card fade-in-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 8v4l3 3"></path>
                            <circle cx="12" cy="12" r="10"></circle>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value">7</span><span class="stat-label">Reports Pending Generation</span><span class="reports-stat-desc">Waiting in the visual queue</span></div>
                </div>
                <div class="stat-card stat-red reports-stat-card fade-in-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                            <path d="M3 5v14c0 1.66 4.03 3 9 3s9-1.34 9-3V5"></path>
                            <path d="M3 12c0 1.66 4.03 3 9 3s9-1.34 9-3"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value">1.8 GB</span><span class="stat-label">Storage Used by Reports</span><span class="reports-stat-desc">PDF, Excel, and CSV exports</span></div>
                </div>
            </section>

            <section class="reports-category-grid" aria-label="Report categories">
                <article class="report-category-card fade-in-card"><span class="report-category-icon report-category-teal"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                        </svg></span>
                    <h2>Patient Reports</h2>
                    <p>Admissions, discharges, patient census, and visit history summaries.</p><button class="btn btn-secondary" type="button">View Reports</button>
                </article>
                <article class="report-category-card fade-in-card"><span class="report-category-icon report-category-blue"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 8v3a7 7 0 0 1-14 0V8"></path>
                            <path d="M12 18v4"></path>
                            <path d="M8 22h8"></path>
                        </svg></span>
                    <h2>Doctor Reports</h2>
                    <p>Consultation volume, schedules, department assignments, and coverage.</p><button class="btn btn-secondary" type="button">View Reports</button>
                </article>
                <article class="report-category-card fade-in-card"><span class="report-category-icon report-category-red"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M8 2v4"></path>
                            <path d="M16 2v4"></path>
                            <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                            <path d="M3 10h18"></path>
                        </svg></span>
                    <h2>Appointment Reports</h2>
                    <p>Bookings, completed visits, queue performance, and no-show activity.</p><button class="btn btn-secondary" type="button">View Reports</button>
                </article>
                <article class="report-category-card fade-in-card"><span class="report-category-icon report-category-green"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 8v13H3V8"></path>
                            <path d="M1 3h22v5H1z"></path>
                            <path d="M10 12h4"></path>
                        </svg></span>
                    <h2>Inventory Reports</h2>
                    <p>Medicine consumption, stock movement, expiry watchlists, and usage.</p><a class="btn btn-secondary" href="inventory-reports.php">View Reports</a>
                </article>
                <article class="report-category-card fade-in-card"><span class="report-category-icon report-category-amber"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 7h-9"></path>
                            <path d="M14 17H5"></path>
                            <circle cx="17" cy="17" r="3"></circle>
                            <circle cx="7" cy="7" r="3"></circle>
                        </svg></span>
                    <h2>Procurement Reports</h2>
                    <p>Purchase requests, supplier bids, orders, and delivery timelines.</p><a class="btn btn-secondary" href="inventory-reports.php">View Reports</a>
                </article>
                <article class="report-category-card fade-in-card"><span class="report-category-icon report-category-purple"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 1v22"></path>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6"></path>
                        </svg></span>
                    <h2>Financial Reports</h2>
                    <p>Revenue summaries, collections, department totals, and cost snapshots.</p><button class="btn btn-secondary" type="button">View Reports</button>
                </article>
                <article class="report-category-card fade-in-card"><span class="report-category-icon report-category-blue"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 2v6"></path>
                            <path d="M15 2v6"></path>
                            <path d="M8 8h8"></path>
                            <path d="M10 8l-2 12h8L14 8"></path>
                        </svg></span>
                    <h2>Laboratory Reports</h2>
                    <p>Lab requests, turnaround summaries, result volumes, and utilization.</p><button class="btn btn-secondary" type="button">View Reports</button>
                </article>
                <article class="report-category-card fade-in-card"><span class="report-category-icon report-category-teal"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M2 4v16"></path>
                            <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                            <path d="M2 17h20"></path>
                        </svg></span>
                    <h2>Hospital Census Reports</h2>
                    <p>Occupancy, bed movement, department activity, and daily census.</p><button class="btn btn-secondary" type="button">View Reports</button>
                </article>
            </section>

            <section class="reports-dashboard-layout">
                <div class="reports-main-column">
                    <section class="card reports-table-card">
                        <div class="card-header reports-card-header">
                            <div>
                                <h2>Reports Library</h2>
                                <p class="card-subtitle">Static sample reports for layout review.</p>
                            </div>
                        </div>
                        <div class="reports-toolbar">
                            <div class="search-field reports-search-field"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <path d="M21 21l-4.35-4.35"></path>
                                </svg><input type="text" placeholder="Search Report" aria-label="Search Report"></div>
                            <div class="reports-filters">
                                <select class="filter-select" aria-label="Category">
                                    <option>All Categories</option>
                                    <option>Patient Reports</option>
                                    <option>Doctor Reports</option>
                                    <option>Appointment Reports</option>
                                    <option>Inventory Reports</option>
                                    <option>Procurement Reports</option>
                                    <option>Financial Reports</option>
                                    <option>Laboratory Reports</option>
                                    <option>Hospital Census Reports</option>
                                </select>
                                <select class="filter-select" aria-label="Department">
                                    <option>All Departments</option>
                                    <option>Emergency</option>
                                    <option>Internal Medicine</option>
                                    <option>Pediatrics</option>
                                    <option>Pharmacy</option>
                                    <option>Laboratory</option>
                                </select>
                                <select class="filter-select" aria-label="Generated By">
                                    <option>Generated By</option>
                                    <option>Admin User</option>
                                    <option>System Scheduler</option>
                                    <option>Finance Desk</option>
                                    <option>Pharmacy Admin</option>
                                </select>
                                <select class="filter-select" aria-label="Status">
                                    <option>All Statuses</option>
                                    <option>Ready</option>
                                    <option>Generating</option>
                                    <option>Scheduled</option>
                                    <option>Archived</option>
                                </select>
                                <input type="date" class="filter-select reports-date-input" aria-label="Date Range">
                                <select class="filter-select" aria-label="Sort">
                                    <option>Newest First</option>
                                    <option>Oldest First</option>
                                    <option>Report Name</option>
                                    <option>Category</option>
                                    <option>File Size</option>
                                </select>
                                <button class="btn-refresh" type="button" aria-label="Refresh reports"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path>
                                        <path d="M3 21v-5h5"></path>
                                        <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path>
                                        <path d="M16 8h5V3"></path>
                                    </svg></button>
                                <button class="btn btn-secondary reports-reset-btn" type="button">Reset Filters</button>
                            </div>
                        </div>

                        <div class="table-wrap reports-table-wrap">
                            <table class="queue-table reports-table">
                                <thead>
                                    <tr>
                                        <th>Report Name</th>
                                        <th>Category</th>
                                        <th>Department</th>
                                        <th>Generated By</th>
                                        <th>Generated Date</th>
                                        <th>File Type</th>
                                        <th>Size</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Monthly Patient Census</strong><span class="reports-row-subtext">Hospital-wide patient movement</span></td>
                                        <td>Patient Reports</td>
                                        <td>All Departments</td>
                                        <td>Admin User</td>
                                        <td>Jul 15, 2026</td>
                                        <td><span class="file-type-pill file-pdf">PDF</span></td>
                                        <td>3.4 MB</td>
                                        <td><span class="status-pill status-report-ready">Ready</span></td>
                                        <td>
                                            <div class="reports-row-actions"><button type="button" class="btn-row-action report-preview-btn" data-report-title="Monthly Patient Census">Preview</button><button type="button" class="btn-row-action">Download</button><button type="button" class="btn-row-action btn-row-danger">Delete</button><button type="button" class="btn-row-action">Duplicate</button></div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Doctor Consultation Volume</strong><span class="reports-row-subtext">Department and physician totals</span></td>
                                        <td>Doctor Reports</td>
                                        <td>Internal Medicine</td>
                                        <td>System Scheduler</td>
                                        <td>Jul 15, 2026</td>
                                        <td><span class="file-type-pill file-excel">Excel</span></td>
                                        <td>1.9 MB</td>
                                        <td><span class="status-pill status-report-generating">Generating</span></td>
                                        <td>
                                            <div class="reports-row-actions"><button type="button" class="btn-row-action report-preview-btn" data-report-title="Doctor Consultation Volume">Preview</button><button type="button" class="btn-row-action">Download</button><button type="button" class="btn-row-action btn-row-danger">Delete</button><button type="button" class="btn-row-action">Duplicate</button></div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Medicine Consumption Summary</strong><span class="reports-row-subtext">Top issued medicines and stock usage</span></td>
                                        <td>Inventory Reports</td>
                                        <td>Pharmacy</td>
                                        <td>Pharmacy Admin</td>
                                        <td>Jul 14, 2026</td>
                                        <td><span class="file-type-pill file-pdf">PDF</span></td>
                                        <td>2.8 MB</td>
                                        <td><span class="status-pill status-report-ready">Ready</span></td>
                                        <td>
                                            <div class="reports-row-actions"><button type="button" class="btn-row-action report-preview-btn" data-report-title="Medicine Consumption Summary">Preview</button><button type="button" class="btn-row-action">Download</button><button type="button" class="btn-row-action btn-row-danger">Delete</button><button type="button" class="btn-row-action">Duplicate</button></div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Procurement Pipeline</strong><span class="reports-row-subtext">Requests, bids, and purchase orders</span></td>
                                        <td>Procurement Reports</td>
                                        <td>Procurement</td>
                                        <td>Admin User</td>
                                        <td>Jul 16, 2026</td>
                                        <td><span class="file-type-pill file-csv">CSV</span></td>
                                        <td>940 KB</td>
                                        <td><span class="status-pill status-report-scheduled">Scheduled</span></td>
                                        <td>
                                            <div class="reports-row-actions"><button type="button" class="btn-row-action report-preview-btn" data-report-title="Procurement Pipeline">Preview</button><button type="button" class="btn-row-action">Download</button><button type="button" class="btn-row-action btn-row-danger">Delete</button><button type="button" class="btn-row-action">Duplicate</button></div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Revenue Summary</strong><span class="reports-row-subtext">Daily department revenue rollup</span></td>
                                        <td>Financial Reports</td>
                                        <td>Finance</td>
                                        <td>Finance Desk</td>
                                        <td>Jul 12, 2026</td>
                                        <td><span class="file-type-pill file-excel">Excel</span></td>
                                        <td>2.2 MB</td>
                                        <td><span class="status-pill status-report-archived">Archived</span></td>
                                        <td>
                                            <div class="reports-row-actions"><button type="button" class="btn-row-action report-preview-btn" data-report-title="Revenue Summary">Preview</button><button type="button" class="btn-row-action">Download</button><button type="button" class="btn-row-action btn-row-danger">Delete</button><button type="button" class="btn-row-action">Duplicate</button></div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="reports-analytics-grid" aria-label="Analytics dashboard">
                        <article class="card report-chart-card">
                            <div class="card-header">
                                <h2>Monthly Patient Admissions</h2><span class="card-subtitle">12 months</span>
                            </div>
                            <div class="mock-bar-chart" aria-hidden="true"><span style="height:42%"></span><span style="height:52%"></span><span style="height:48%"></span><span style="height:66%"></span><span style="height:58%"></span><span style="height:74%"></span><span style="height:69%"></span><span style="height:82%"></span><span style="height:76%"></span><span style="height:88%"></span><span style="height:81%"></span><span style="height:92%"></span></div>
                        </article>
                        <article class="card report-chart-card">
                            <div class="card-header">
                                <h2>Doctor Consultation Volume</h2><span class="card-subtitle">This quarter</span>
                            </div>
                            <div class="mock-line-chart" aria-hidden="true"><span class="line-dot dot-1"></span><span class="line-dot dot-2"></span><span class="line-dot dot-3"></span><span class="line-dot dot-4"></span></div>
                        </article>
                        <article class="card report-chart-card">
                            <div class="card-header">
                                <h2>Medicine Consumption</h2><span class="card-subtitle">Top medicines</span>
                            </div>
                            <div class="mock-progress-list">
                                <div><span>Paracetamol</span><strong>84%</strong><i style="width:84%"></i></div>
                                <div><span>Amoxicillin</span><strong>67%</strong><i style="width:67%"></i></div>
                                <div><span>Ibuprofen</span><strong>49%</strong><i style="width:49%"></i></div>
                            </div>
                        </article>
                        <article class="card report-chart-card">
                            <div class="card-header">
                                <h2>Inventory Usage</h2><span class="card-subtitle">Supply movement</span>
                            </div>
                            <div class="mock-donut-chart" aria-hidden="true"><span>72%</span></div>
                        </article>
                        <article class="card report-chart-card">
                            <div class="card-header">
                                <h2>Hospital Occupancy</h2><span class="card-subtitle">Today</span>
                            </div>
                            <div class="mock-donut-chart mock-donut-blue" aria-hidden="true"><span>81%</span></div>
                        </article>
                        <article class="card report-chart-card">
                            <div class="card-header">
                                <h2>Revenue Summary</h2><span class="card-subtitle">Month to date</span>
                            </div>
                            <div class="mock-revenue-summary"><strong>PHP 2.4M</strong><span>+12.5% from previous month</span>
                                <div class="mock-sparkline" aria-hidden="true"></div>
                            </div>
                        </article>
                        <article class="card report-chart-card">
                            <div class="card-header">
                                <h2>Appointment Trends</h2><span class="card-subtitle">Weekly</span>
                            </div>
                            <div class="mock-area-chart" aria-hidden="true"></div>
                        </article>
                        <article class="card report-chart-card">
                            <div class="card-header">
                                <h2>Department Activity</h2><span class="card-subtitle">Current week</span>
                            </div>
                            <div class="mock-activity-grid" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
                        </article>
                        <article class="card report-chart-card">
                            <div class="card-header">
                                <h2>Referral Statistics</h2><span class="card-subtitle">Inbound vs outbound</span>
                            </div>
                            <div class="mock-horizontal-chart">
                                <div><span>Inbound</span><i style="width:76%"></i><strong>76%</strong></div>
                                <div><span>Outbound</span><i style="width:42%"></i><strong>42%</strong></div>
                                <div><span>Internal</span><i style="width:64%"></i><strong>64%</strong></div>
                            </div>
                        </article>
                    </section>

                    <section class="card scheduled-reports-card">
                        <div class="card-header">
                            <h2>Scheduled Reports</h2><span class="card-subtitle">Recurring report queue</span>
                        </div>
                        <div class="table-wrap">
                            <table class="queue-table compact-report-table">
                                <thead>
                                    <tr>
                                        <th>Report</th>
                                        <th>Frequency</th>
                                        <th>Next Run</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Daily Census Snapshot</td>
                                        <td>Daily</td>
                                        <td>Jul 16, 2026 06:00</td>
                                        <td><span class="status-pill status-schedule-active">Active</span></td>
                                        <td><button class="btn-row-action" type="button">View</button><button class="btn-row-action" type="button">Edit</button></td>
                                    </tr>
                                    <tr>
                                        <td>Consultation Volume</td>
                                        <td>Weekly</td>
                                        <td>Jul 20, 2026 08:00</td>
                                        <td><span class="status-pill status-schedule-upcoming">Upcoming</span></td>
                                        <td><button class="btn-row-action" type="button">View</button><button class="btn-row-action" type="button">Edit</button></td>
                                    </tr>
                                    <tr>
                                        <td>Revenue Summary</td>
                                        <td>Monthly</td>
                                        <td>Aug 1, 2026 07:00</td>
                                        <td><span class="status-pill status-schedule-paused">Paused</span></td>
                                        <td><button class="btn-row-action" type="button">View</button><button class="btn-row-action" type="button">Edit</button></td>
                                    </tr>
                                    <tr>
                                        <td>Procurement Review</td>
                                        <td>Quarterly</td>
                                        <td>Oct 1, 2026 09:00</td>
                                        <td><span class="status-pill status-schedule-active">Active</span></td>
                                        <td><button class="btn-row-action" type="button">View</button><button class="btn-row-action" type="button">Edit</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="reports-bottom-grid">
                        <div class="card recent-downloads-card">
                            <div class="card-header">
                                <h2>Recent Downloads</h2><span class="card-subtitle">Latest file activity</span>
                            </div>
                            <div class="table-wrap">
                                <table class="queue-table compact-report-table">
                                    <thead>
                                        <tr>
                                            <th>Report</th>
                                            <th>Downloaded By</th>
                                            <th>Download Date</th>
                                            <th>File Type</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Monthly Patient Census</td>
                                            <td>Admin User</td>
                                            <td>Jul 15, 2026</td>
                                            <td><span class="file-type-pill file-pdf">PDF</span></td>
                                        </tr>
                                        <tr>
                                            <td>Revenue Summary</td>
                                            <td>Finance Desk</td>
                                            <td>Jul 14, 2026</td>
                                            <td><span class="file-type-pill file-excel">Excel</span></td>
                                        </tr>
                                        <tr>
                                            <td>Procurement Pipeline</td>
                                            <td>Pharmacy Admin</td>
                                            <td>Jul 13, 2026</td>
                                            <td><span class="file-type-pill file-csv">CSV</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card reports-empty-card">
                            <div class="empty-state reports-empty-state">
                                <div class="empty-illustration reports-empty-illustration"><svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M3 3v18h18"></path>
                                        <path d="M18 17V9"></path>
                                        <path d="M13 17V5"></path>
                                        <path d="M8 17v-3"></path>
                                    </svg></div>
                                <p><strong>No Reports Found</strong></p>
                                <p>No reports match the selected filters.</p><button class="btn btn-primary" type="button">Generate New Report</button>
                            </div>
                        </div>
                    </section>

                    <section class="reports-loading-demo" aria-label="Loading state examples">
                        <div class="reports-skeleton-card skeleton"></div>
                        <div class="reports-skeleton-table skeleton"></div>
                        <div class="reports-skeleton-chart skeleton"></div>
                    </section>
                </div>

                <aside class="reports-side-column">
                    <section class="card side-report-card">
                        <div class="card-header">
                            <h2>Favorite Reports</h2>
                        </div>
                        <div class="side-report-list">
                            <div><span class="side-report-icon file-pdf">PDF</span><strong>Patient Census</strong><button class="btn-row-action" type="button">Quick Open</button></div>
                            <div><span class="side-report-icon file-excel">XLS</span><strong>Revenue Summary</strong><button class="btn-row-action" type="button">Quick Open</button></div>
                            <div><span class="side-report-icon file-csv">CSV</span><strong>Inventory Usage</strong><button class="btn-row-action" type="button">Quick Open</button></div>
                        </div>
                    </section>
                    <section class="card side-report-card">
                        <div class="card-header">
                            <h2>Recently Viewed</h2>
                        </div>
                        <div class="recently-viewed-list">
                            <div><strong>Doctor Consultation Volume</strong><span>Opened Jul 15, 2026 by Admin User</span><button class="btn-row-action report-preview-btn" data-report-title="Doctor Consultation Volume" type="button">Quick Preview</button></div>
                            <div><strong>Hospital Occupancy</strong><span>Opened Jul 14, 2026 by Census Clerk</span><button class="btn-row-action report-preview-btn" data-report-title="Hospital Occupancy" type="button">Quick Preview</button></div>
                            <div><strong>Appointment Trends</strong><span>Opened Jul 13, 2026 by Front Desk</span><button class="btn-row-action report-preview-btn" data-report-title="Appointment Trends" type="button">Quick Preview</button></div>
                        </div>
                    </section>
                </aside>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>
        </main>
    </div>

    <div class="pdf-viewer-backdrop" id="reportPreviewModal" aria-hidden="true">
        <section class="pdf-viewer" role="dialog" aria-modal="true" aria-labelledby="reportPreviewTitle">
            <div class="pdf-viewer-toolbar">
                <div><strong id="reportPreviewTitle">Monthly Patient Census</strong><span>PDF Preview</span></div>
                <div class="pdf-toolbar-actions">
                    <button class="btn btn-primary" type="button">Download PDF</button>
                    <button class="btn btn-secondary" type="button">Print</button>
                    <button class="pdf-icon-btn" type="button" id="pdfZoomIn" aria-label="Zoom In">+</button>
                    <button class="pdf-icon-btn" type="button" id="pdfZoomOut" aria-label="Zoom Out">-</button>
                    <button class="pdf-icon-btn" type="button" id="pdfFullscreen" aria-label="Fullscreen">[]</button>
                    <button class="pdf-icon-btn" type="button" id="reportPreviewClose" aria-label="Close">x</button>
                </div>
            </div>
            <div class="pdf-stage">
                <article class="pdf-paper" id="pdfPaper">
                    <div class="pdf-watermark">CONFIDENTIAL</div>
                    <header class="pdf-hospital-header">
                        <div class="pdf-logo">GM</div>
                        <div>
                            <h2>GabayMed Hospital</h2>
                            <p>123 Healthway Avenue, Manila, Philippines</p>
                            <p>Tel: (02) 8123-4567 | reports@gabaymed.local</p>
                        </div>
                    </header>
                    <div class="pdf-report-heading">
                        <h1 id="pdfReportHeading">Monthly Patient Census</h1>
                        <div><span>Generated Date</span><strong>July 15, 2026</strong></div>
                        <div><span>Prepared By</span><strong>Admin User</strong></div>
                        <div><span>Department</span><strong>All Departments</strong></div>
                    </div>
                    <hr>
                    <section class="pdf-section">
                        <h3>Executive Summary</h3>
                        <p>This sample hospital report summarizes admissions, occupancy, discharge movement, and department-level performance using static placeholder data for UI review.</p>
                    </section>
                    <section class="pdf-metrics-grid">
                        <div><span>Patient Admissions</span><strong>1,284</strong></div>
                        <div><span>Discharges</span><strong>1,102</strong></div>
                        <div><span>Occupancy Rate</span><strong>81%</strong></div>
                        <div><span>Average Stay</span><strong>3.8 days</strong></div>
                    </section>
                    <section class="pdf-two-column">
                        <div class="pdf-panel">
                            <h3>Patient Statistics</h3>
                            <div class="mock-bar-chart pdf-chart"><span style="height:45%"></span><span style="height:62%"></span><span style="height:56%"></span><span style="height:78%"></span><span style="height:68%"></span><span style="height:86%"></span></div>
                        </div>
                        <div class="pdf-panel">
                            <h3>Hospital Metrics</h3>
                            <div class="mock-donut-chart preview-donut"><span>81%</span></div>
                        </div>
                    </section>
                    <section class="pdf-section">
                        <h3>Department Table</h3>
                        <table class="pdf-table">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Admissions</th>
                                    <th>Discharges</th>
                                    <th>Occupancy</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Emergency</td>
                                    <td>112</td>
                                    <td>105</td>
                                    <td>82%</td>
                                </tr>
                                <tr>
                                    <td>Internal Medicine</td>
                                    <td>86</td>
                                    <td>73</td>
                                    <td>78%</td>
                                </tr>
                                <tr>
                                    <td>Pediatrics</td>
                                    <td>54</td>
                                    <td>49</td>
                                    <td>66%</td>
                                </tr>
                                <tr>
                                    <td>Laboratory Referrals</td>
                                    <td>221</td>
                                    <td>198</td>
                                    <td>74%</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                    <footer class="pdf-footer"><span>GabayMed Hospital Management System | Confidential Hospital Report</span><span>Page 1 of 1</span></footer>
                </article>
                <div class="pdf-skeleton skeleton" aria-hidden="true"></div>
            </div>
        </section>
    </div>

    <script src="../assets/js/hospital-reports.js" defer></script>
</body>

</html>