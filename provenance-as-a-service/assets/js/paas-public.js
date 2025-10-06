/**
 * PAAS Public Scripts
 */
document.addEventListener('DOMContentLoaded', () => {
    const verifyBtn = document.querySelector('.paas-verify-btn');
    if (verifyBtn) {
        verifyBtn.addEventListener('click', async (e) => {
            const button = e.target;
            const postId = button.dataset.postId;
            const statusEl = button.nextElementSibling;

            statusEl.textContent = 'Verifying...';

            const formData = new FormData();
            formData.append('action', 'paas_verify_manifest');
            formData.append('post_id', postId);
            formData.append('nonce', paas_ajax.nonce);

            try {
                const response = await fetch(paas_ajax.ajax_url, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    statusEl.textContent = result.data.message;
                    statusEl.style.color = 'green';
                } else {
                    statusEl.textContent = result.data.message;
                    statusEl.style.color = 'red';
                }
            } catch (error) {
                statusEl.textContent = 'An error occurred.';
                statusEl.style.color = 'red';
            }
        });
    }
});
