document.addEventListener('DOMContentLoaded', function () {
    const API_URL = 'api/handover_handler.php';
    const cobaForm = document.getElementById('cobaForm'); // 'cobaForm' could be 'testForm' or 'trialForm', using original for consistency
    const dataTableBody = document.querySelector('#dataTable tbody');
    const submitBtn = document.getElementById('submitBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const sCustomerKeyForEditInput = document.getElementById('s_customer_key_for_edit');
    const toggleFormBtn = document.getElementById('toggleFormBtn');

    // List of all form input IDs corresponding to the 't_handover' table columns
    // This is VERY IMPORTANT and must match the 'id' attribute in HTML and the column names in the backend
    const formInputIds = [
        's_customer', 'id_tkc_cd', 's_name', 's_address', 's_type', 'dt_from', 'dt_to',
        'n_advisory_fee', 'n_account_closing_fee', 'n_others_fee', 's_rep_name', 's_rep_personal',
        's_rep_partner_name', 's_rep_partner_personal', 's_rep_others_name', 's_rep_others_personal',
        's_corp_tel', 's_corp_fax', 's_rep_tel', 's_rep_email', 's_rep_contact', 'n_recovery',
        'n_advisory_yet', 'n_account_closing_yet', 'n_others_yet', 'dt_recovery', 's_recover_reason',
        'dt_completed', 'n_place', 's_place_others', 's_convenient', 's_required_time',
        's_affiliated_company', 's_heeding_audit', 'n_interim_return', 'n_consumption_tax',
        's_heeding_settlement', 'dt_last_tax_audit', 's_tax_audit_memo', 'n_exemption_for_dependents',
        's_exemption_for_dependents', // Column 41 (VARCHAR)
        'n_last_year_end_adjustment', 's_last_year_end_adjustment', 'n_payroll_report',
        's_payroll_report', // Column 45 (VARCHAR)
        'n_legal_report', 's_legal_report', // Column 47 (VARCHAR)
        'n_deadline_exceptions', 's_deadline_exceptions', // Column 49 (VARCHAR)
        's_late_payment', 'n_depreciable_assets_tax', 's_depreciable_assets_tax', // Column 53
        'n_final_tax_return', 's_final_tax_return', // Column 55
        's_taxpayer_name', 'n_health_insurance', 'n_employment_insurance', 'n_workers_accident_insurance',
        'n_late_payment_status', // Column 60 (TINYINT), ensure 'name' in HTML is 'n_late_payment_status' (or adjust if it refers to s_late_payment behavior)
        'n_greetings_method', 's_special_notes', 's_other_notes', 's_predecessor',
        's_superior', 'dt_submitted', 'dt_approved', 's_approved', 'dt_approved_1', 's_approved_1',
        'dt_approved_2', 's_approved_2', 'dt_approved_3', 's_approved_3', 'dt_approved_4', 's_approved_4',
        'dt_approved_5', 's_approved_5', 'dt_checked', 's_checked', 'dt_denied', 's_in_charge'
    ];


    // Function to fetch and display data
    async function fetchData() {
        try {
            const response = await fetch(API_URL);
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ message: "Failed to fetch data. Status: " + response.status }));
                throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
            }
            const data = await response.json();
            renderTable(data);
        } catch (error) {
            console.error('Error fetching data:', error);
            alert('Failed to fetch data: ' + error.message);
            dataTableBody.innerHTML = `<tr><td colspan="5">Failed to load data. ${error.message}</td></tr>`;
        }
    }

    // Function to render the table
    function renderTable(data) {
        dataTableBody.innerHTML = ''; // Clear the table
        if (!data || data.length === 0) {
            dataTableBody.innerHTML = '<tr><td colspan="5">No data available.</td></tr>';
            return;
        }
        data.forEach(item => {
            const row = dataTableBody.insertRow();
            row.insertCell().textContent = item.s_customer;
            row.insertCell().textContent = item.s_name;
            row.insertCell().textContent = item.s_type;
            // Using 'en-GB' for a common English locale, adjust if needed (e.g., 'en-US')
            row.insertCell().textContent = item.dt_submitted ? new Date(item.dt_submitted).toLocaleString('en-GB') : '-';

            const actionsCell = row.insertCell();
            actionsCell.classList.add('actions');
            const editBtn = document.createElement('button');
            editBtn.textContent = 'Edit';
            editBtn.classList.add('edit-btn');
            editBtn.onclick = () => handleEdit(item.s_customer);
            actionsCell.appendChild(editBtn);

            const deleteBtn = document.createElement('button');
            deleteBtn.textContent = 'Delete';
            deleteBtn.classList.add('delete-btn');
            deleteBtn.onclick = () => handleDelete(item.s_customer);
            actionsCell.appendChild(deleteBtn);
        });
    }

    // Function to handle form submission (Create/Update)
    cobaForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        const formData = new FormData(cobaForm);
        const data = {};

        formInputIds.forEach(id => {
            const inputElement = document.getElementById(id);
            if (inputElement) {
                if (inputElement.type === 'checkbox') {
                    data[inputElement.name || id] = inputElement.checked ? 1 : 0;
                } else if (inputElement.type === 'datetime-local' && inputElement.value) {
                     // Format to YYYY-MM-DD HH:MM:SS if required by the backend
                     // datetime-local input produces YYYY-MM-DDTHH:MM
                     // MySQL usually accepts this, or it can be changed if necessary
                    let dateTimeValue = inputElement.value;
                    if (dateTimeValue) { // Ensure there is a value
                        dateTimeValue = dateTimeValue.replace('T', ' ');
                        // Add seconds if not present and the backend requires them
                        if (dateTimeValue.length === 16) { // YYYY-MM-DD HH:MM
                            dateTimeValue += ':00';
                        }
                    }
                    data[inputElement.name || id] = dateTimeValue || null;
                } else {
                    // For other fields, if empty and can be NULL, send null
                    // If NOT NULL, frontend/backend validation will handle it
                    data[inputElement.name || id] = inputElement.value.trim() === '' ? null : inputElement.value.trim();
                }
            }
        });

        // Ensure primary key 's_customer' is in the data
        if (!data.s_customer && !sCustomerKeyForEditInput.value) {
            alert('Customer ID (s_customer) must be filled!');
            return;
        }


        const s_customer_edit_key = sCustomerKeyForEditInput.value;
        let url = API_URL;
        let method = 'POST';

        if (s_customer_edit_key) { // Edit Mode
            url += `?s_customer=${s_customer_edit_key}`;
            method = 'PUT';
        }

        try {
            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data),
            });

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || `HTTP error! status: ${response.status}`);
            }

            alert(result.message); // Assuming API returns English messages
            fetchData(); // Reload table data
            resetForm();
        } catch (error) {
            console.error('Error submitting form:', error);
            alert('Failed to save data: ' + error.message);
        }
    });

    // Function to handle edit
    async function handleEdit(s_customer) {
        try {
            const response = await fetch(`${API_URL}?s_customer=${s_customer}`);
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ message: "Failed to fetch data for editing. Status: " + response.status }));
                throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
            }
            const item = await response.json();

            formInputIds.forEach(id => {
                const inputElement = document.getElementById(id);
                if (inputElement) {
                    // The field name in the JSON item must be the same as the input's 'name' or 'id'
                    const fieldName = inputElement.name || id;
                    if (item.hasOwnProperty(fieldName)) {
                        if (inputElement.type === 'checkbox') {
                            inputElement.checked = !!parseInt(item[fieldName]); // Convert 1/0 to true/false
                        } else if (inputElement.type === 'date' && item[fieldName]) {
                            inputElement.value = item[fieldName].split(' ')[0]; // Take only the date part
                        } else if (inputElement.type === 'datetime-local' && item[fieldName]) {
                            // Format YYYY-MM-DDTHH:MM
                            let dtValue = item[fieldName];
                            if (dtValue) {
                                dtValue = dtValue.replace(' ', 'T');
                                // Remove seconds if present, as datetime-local does not display them
                                if (dtValue.length > 16) {
                                    dtValue = dtValue.substring(0, 16);
                                }
                            }
                            inputElement.value = dtValue;
                        }
                        else {
                            inputElement.value = item[fieldName] === null ? '' : item[fieldName];
                        }
                    } else {
                         // If the field is not in the response data, clear it
                        if (inputElement.type === 'checkbox') inputElement.checked = false;
                        else inputElement.value = '';
                    }
                }
            });

            sCustomerKeyForEditInput.value = s_customer; // Store the ID being edited
            document.getElementById('s_customer').readOnly = true; // PK should not be changed during edit
            submitBtn.textContent = 'Save Changes';
            cobaForm.style.display = 'block'; // Display the form
            cobaForm.scrollIntoView({ behavior: 'smooth' });

        } catch (error) {
            console.error('Error fetching item for edit:', error);
            alert('Failed to fetch data for editing: ' + error.message);
        }
    }

    // Function to handle delete
    async function handleDelete(s_customer) {
        if (!confirm(`Are you sure you want to delete data with Customer ID: ${s_customer}?`)) {
            return;
        }

        try {
            const response = await fetch(`${API_URL}?s_customer=${s_customer}`, {
                method: 'DELETE',
            });
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || `HTTP error! status: ${response.status}`);
            }

            alert(result.message); // Assuming API returns English messages
            fetchData(); // Reload table data
        } catch (error) {
            console.error('Error deleting item:', error);
            alert('Failed to delete data: ' + error.message);
        }
    }

    // Function to reset the form
    function resetForm() {
        cobaForm.reset();
        sCustomerKeyForEditInput.value = '';
        document.getElementById('s_customer').readOnly = false;
        submitBtn.textContent = 'Add New';
        // cobaForm.style.display = 'none'; // Hide the form after reset if desired
    }

    // Event listener for the cancel button
    cancelBtn.addEventListener('click', resetForm);

    // Event listener for the form toggle button
    toggleFormBtn.addEventListener('click', () => {
        cobaForm.style.display = cobaForm.style.display === 'none' ? 'block' : 'none';
        if (cobaForm.style.display === 'block') {
             cobaForm.scrollIntoView({ behavior: 'smooth' });
        }
    });


    // Load initial data when the page loads
    fetchData();
});