/**
 * J&J Grocery POS - Main JavaScript
 * Core functionality for modals, forms, validation
 */

// Modal handling
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
    }
}

// Close modal when clicking outside
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                modal.classList.remove('active');
            }
        });
    });
});

// Form validation helpers
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function validateRequired(value) {
    return value && value.trim().length > 0;
}

function validateMinLength(value, min) {
    return value && value.length >= min;
}

function validateNumber(value) {
    return !isNaN(value) && value !== '';
}

// Display messages
function showMessage(text, type = 'info') {
    const messageDiv = document.createElement('div');
    messageDiv.className = `alert alert-${type}`;
    messageDiv.innerHTML = text;
    
    const container = document.querySelector('.container') || document.body;
    container.insertBefore(messageDiv, container.firstChild);

    setTimeout(() => {
        messageDiv.remove();
    }, 5000);
}

// Table filtering
function filterTable(inputId, tableId, columnIndex = 0) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    const rows = table ? table.getElementsByTagName('tbody')[0]?.getElementsByTagName('tr') : [];

    input?.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        Array.from(rows).forEach(row => {
            const cell = row.getElementsByTagName('td')[columnIndex];
            if (cell) {
                const text = cell.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            }
        });
    });
}

// Debounce function for search
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Confirmation dialog
function confirmAction(message) {
    return confirm(message);
}

// Format currency (used in POS)
function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2);
}

// Clear form
function clearForm(formId) {
    const form = document.getElementById(formId);
    if (form) {
        form.reset();
    }
}

// Focus management
function setFocus(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.focus();
    }
}

// Dynamic form field population
function populateField(fieldId, value) {
    const field = document.getElementById(fieldId);
    if (field) {
        field.value = value;
    }
}

// Disable/Enable button
function disableButton(buttonId, disable = true) {
    const button = document.getElementById(buttonId);
    if (button) {
        button.disabled = disable;
        button.style.opacity = disable ? '0.5' : '1';
    }
}

// Loading state
function setLoading(elementId, loading = true) {
    const element = document.getElementById(elementId);
    if (element) {
        if (loading) {
            element.innerHTML = '<span style="opacity: 0.6;">Loading...</span>';
            element.disabled = true;
        } else {
            element.disabled = false;
        }
    }
}

// API call helper
async function apiCall(url, method = 'GET', data = null) {
    try {
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json'
            }
        };

        if (data) {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(url, options);
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);
        showMessage('Error communicating with server', 'danger');
        return null;
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Alt+S for Save (common in modals)
    if (e.altKey && e.key === 's') {
        const submitBtn = document.querySelector('.modal.active button[type="submit"]');
        if (submitBtn) {
            submitBtn.click();
            e.preventDefault();
        }
    }
    
    // Alt+C for Cancel
    if (e.altKey && e.key === 'c') {
        const modal = document.querySelector('.modal.active');
        if (modal) {
            const closeBtn = modal.querySelector('.modal-close');
            if (closeBtn) closeBtn.click();
            e.preventDefault();
        }
    }
});

// Print function
function printPage(elementId = null) {
    if (elementId) {
        const element = document.getElementById(elementId);
        if (element) {
            const printWindow = window.open('', '', 'height=600,width=800');
            printWindow.document.write('<pre>' + element.textContent + '</pre>');
            printWindow.document.close();
            printWindow.print();
        }
    } else {
        window.print();
    }
}

// Format date (for display)
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('fil-PH', { year: 'numeric', month: '2-digit', day: '2-digit' });
}

// Format datetime (for display)
function formatDateTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('fil-PH', { 
        year: 'numeric', 
        month: '2-digit', 
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Toast notification (alternative to alert)
function showToast(message, duration = 3000) {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: var(--color-primary);
        color: white;
        padding: 16px 24px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 9999;
        animation: slideUp 0.3s ease;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.remove();
    }, duration);
}

// Export table to CSV (utility function)
function exportTableToCSV(tableId, filename = 'export.csv') {
    const table = document.getElementById(tableId);
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll('tr');

    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const csvRow = Array.from(cols).map(col => '"' + col.textContent.trim() + '"').join(',');
        csv.push(csvRow);
    });

    const csvContent = csv.join('\n');
    const link = document.createElement('a');
    link.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csvContent);
    link.download = filename;
    link.click();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Add active class to current nav link
    const currentPage = window.location.pathname.split('/').pop();
    document.querySelectorAll('.navbar a').forEach(link => {
        const linkPage = link.getAttribute('href').split('/').pop();
        if (linkPage === currentPage || (currentPage === '' && linkPage === 'index.php')) {
            link.parentElement.classList.add('active');
        }
    });
});

// Smooth scroll to element
function scrollToElement(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth' });
    }
}

// Check internet connection
function isOnline() {
    return navigator.onLine;
}

document.addEventListener('offline', function() {
    showMessage('You are offline', 'warning');
});

document.addEventListener('online', function() {
    showMessage('Connection restored', 'success');
});
