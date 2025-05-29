// Description: JavaScript logic for customer CRUD frontend interactions.

document.addEventListener('DOMContentLoaded', () => {
    // ==== DOM Elements ====
    const customerTableBody = document.getElementById('customerTableBody');
    const addCustomerBtn = document.getElementById('addCustomerBtn');
    const customerModal = document.getElementById('customerModal');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const cancelModalBtn = document.getElementById('cancelModalBtn');
    const customerForm = document.getElementById('customerForm');
    const modalTitle = document.getElementById('modalTitle');
    const saveCustomerBtn = document.getElementById('saveCustomerBtn');
    const sCustomerInput = document.getElementById('s_customer');
    const idTkcCdInput = document.getElementById('id_tkc_cd');
    const customerIdKeyInput = document.getElementById('customerIdKey');
    const notification = document.getElementById('notification');
    const notificationMessage = document.getElementById('notificationMessage');

    // ==== API URL ====
    const apiUrl = 'api/customer_handler.php';
    let currentEditCustomerId = null;

    // ==== Notification Function ====
    function showNotification(message, type = 'success') {
        notificationMessage.textContent = message;
        notification.classList.remove(
            'hidden', 'bg-green-100', 'text-green-700',
            'bg-red-100', 'text-red-700',
            'bg-yellow-100', 'text-yellow-700'
        );

        if (type === 'success') {
            notification.classList.add('bg-green-100', 'text-green-700');
        } else if (type === 'error') {
            notification.classList.add('bg-red-100', 'text-red-700');
        } else if (type === 'warning') {
            notification.classList.add('bg-yellow-100', 'text-yellow-700');
        }

        notification.classList.remove('hidden');
        setTimeout(() => {
            notification.classList.add('hidden');
        }, 5000);
    }

    // ==== Modal Functions ====
    function openModal(isEdit = false, customer = null) {
        customerForm.reset();

        if (isEdit && customer) {
            modalTitle.textContent = 'Edit Customer';
            sCustomerInput.value = customer.s_customer;
            idTkcCdInput.value = customer.id_tkc_cd || '';
            customerIdKeyInput.value = customer.s_customer; // Store the original key for PUT request
            currentEditCustomerId = customer.s_customer;
        } else {
            modalTitle.textContent = 'Add New Customer';
            customerIdKeyInput.value = ''; // No key needed for new customer
            currentEditCustomerId = null;
        }

        customerModal.classList.remove('hidden');
        setTimeout(() => {
            customerModal.querySelector('.modal-content').classList.remove('scale-95', 'opacity-0');
            customerModal.querySelector('.modal-content').classList.add('scale-100', 'opacity-100');
        }, 50);

        customerModal.classList.add('modal-active');
    }

    function closeModal() {
        customerModal.querySelector('.modal-content').classList.add('scale-95', 'opacity-0');
        customerModal.querySelector('.modal-content').classList.remove('scale-100', 'opacity-100');

        setTimeout(() => {
            customerModal.classList.add('hidden');
            customerModal.classList.remove('modal-active');
        }, 250);
    }

    // ==== Fetch & Render Customers ====
    async function fetchCustomers() {
        try {
            const response = await fetch(apiUrl);
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ message: "Failed to retrieve customer data." }));
                throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
            }
            const customers = await response.json();
            renderCustomers(customers);
        } catch (error) {
            console.error('Error fetching customers:', error);
            customerTableBody.innerHTML = `<tr><td colspan="3" class="text-center p-5 text-red-500">Failed to load data: ${error.message}</td></tr>`;
            showNotification(`Failed to load data: ${error.message}`, 'error');
        }
    }

    function renderCustomers(customers) {
        customerTableBody.innerHTML = '';

        if (customers.length === 0) {
            customerTableBody.innerHTML = '<tr><td colspan="3" class="text-center p-5 text-gray-500">No customer data available.</td></tr>';
            return;
        }

        customers.forEach(customer => {
            const row = customerTableBody.insertRow();
            row.classList.add('border-b', 'border-gray-200', 'hover:bg-gray-100');

            row.insertCell().textContent = customer.s_customer;
            row.insertCell().textContent = customer.id_tkc_cd || '-';

            const actionsCell = row.insertCell();
            actionsCell.classList.add('px-5', 'py-3', 'text-sm'); // Adjusted padding for consistency

            const editButton = document.createElement('button');
            editButton.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-500 hover:text-blue-700" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828zM5 12V7.172l2.586-2.586a2 2 0 012.828 0L18 12.172V15a2 2 0 01-2 2H5a2 2 0 01-2-2v-3z" />
                </svg>`;
            editButton.title = "Edit";
            editButton.classList.add('mr-2', 'focus:outline-none');
            editButton.onclick = () => openModal(true, customer);
            actionsCell.appendChild(editButton);

            const deleteButton = document.createElement('button');
            deleteButton.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-500 hover:text-red-700" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>`;
            deleteButton.title = "Delete";
            deleteButton.classList.add('focus:outline-none');
            deleteButton.onclick = () => deleteCustomer(customer.s_customer);
            actionsCell.appendChild(deleteButton);
        });
    }

    // ==== Form Submit Handler ====
    if (customerForm) {
        customerForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            saveCustomerBtn.disabled = true;
            saveCustomerBtn.textContent = 'Saving...';

            const s_customer = sCustomerInput.value.trim();
            const id_tkc_cd = idTkcCdInput.value.trim() || null; // Send null if empty
            const old_s_customer_key = customerIdKeyInput.value; // For identifying the record to update

            if (!s_customer) {
                showNotification('Customer ID cannot be empty.', 'error');
                saveCustomerBtn.disabled = false;
                saveCustomerBtn.textContent = 'Save';
                return;
            }

            let url = apiUrl;
            let method = 'POST';
            let bodyData = { s_customer, id_tkc_cd };

            if (currentEditCustomerId) { // If this is an edit operation
                method = 'PUT';
                // For PUT, the identifier is usually in the URL or as a specific field in the body
                // Assuming your API expects the old s_customer in the query for PUT
                url = `${apiUrl}?s_customer=${encodeURIComponent(old_s_customer_key)}`;
                // If your API needs the old key in the body for PUT:
                bodyData.s_customer_key = old_s_customer_key; // or however your API expects it
            }


            try {
                const response = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(bodyData),
                });

                const result = await response.json();

                if (!response.ok) {
                    throw new Error(result.message || `HTTP error! status: ${response.status}`);
                }

                showNotification(result.message || (currentEditCustomerId ? 'Customer updated successfully.' : 'Customer added successfully.'), 'success');
                closeModal();
                fetchCustomers(); // Refresh the table
            } catch (error) {
                showNotification(`Failed to save data: ${error.message}`, 'error');
                console.error('Save error:', error);
            } finally {
                saveCustomerBtn.disabled = false;
                saveCustomerBtn.textContent = 'Save';
            }
        });
    }

    // ==== Delete Customer Function (Example, assuming API structure) ====
    async function deleteCustomer(customerId) {
        if (!confirm(`Are you sure you want to delete customer ${customerId}?`)) {
            return;
        }

        try {
            const response = await fetch(`${apiUrl}?s_customer=${encodeURIComponent(customerId)}`, {
                method: 'DELETE',
            });

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || `HTTP error! status: ${response.status}`);
            }

            showNotification(result.message || 'Customer deleted successfully.', 'success');
            fetchCustomers(); // Refresh the table
        } catch (error) {
            showNotification(`Failed to delete customer: ${error.message}`, 'error');
            console.error('Delete error:', error);
        }
    }


    // ==== Event Listeners ====
    if (addCustomerBtn) addCustomerBtn.addEventListener('click', () => openModal());
    if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
    if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);

    // Close modal if clicked outside of the modal content
    if (customerModal) {
        customerModal.addEventListener('click', (event) => {
            if (event.target === customerModal) { // Check if the click is on the modal backdrop
                closeModal();
            }
        });
    }


    // ==== Initial Fetch ====
    fetchCustomers();
});