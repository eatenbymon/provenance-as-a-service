const paasCrypto = {
    /**
     * Generate an ECDSA P-256 key pair.
     * @returns {Promise<CryptoKeyPair>}
     */
    generateKeyPair: async function() {
        return await window.crypto.subtle.generateKey(
            {
                name: "ECDSA",
                namedCurve: "P-256",
            },
            true, // extractable
            ["sign", "verify"]
        );
    },

    /**
     * Export a CryptoKey to PEM format.
     * @param {string} type - 'public' or 'private'
     * @param {CryptoKey} key
     * @returns {Promise<string>}
     */
    exportKeyToPem: async function(type, key) {
        const format = type === 'public' ? 'spki' : 'pkcs8';
        const exported = await window.crypto.subtle.exportKey(format, key);
        const exportedAsString = String.fromCharCode.apply(null, new Uint8Array(exported));
        const exportedAsBase64 = window.btoa(exportedAsString);
        const header = type === 'public' ? '-----BEGIN PUBLIC KEY-----' : '-----BEGIN PRIVATE KEY-----';
        const footer = type === 'public' ? '-----END PUBLIC KEY-----' : '-----END PRIVATE KEY-----';
        return `${header}\n${exportedAsBase64}\n${footer}`;
    },

    /**
     * Import a PEM-formatted private key.
     * @param {string} pem
     * @returns {Promise<CryptoKey>}
     */
    importPrivateKey: async function(pem) {
        const pemHeader = "-----BEGIN PRIVATE KEY-----";
        const pemFooter = "-----END PRIVATE KEY-----";
        const pemContents = pem.substring(pemHeader.length, pem.length - pemFooter.length).trim();
        const binaryDer = this.base64ToArrayBuffer(pemContents);

        return await window.crypto.subtle.importKey(
            "pkcs8",
            binaryDer,
            {
                name: "ECDSA",
                namedCurve: "P-256",
            },
            true,
            ["sign"]
        );
    },

    /**
     * Sign data with a private key.
     * @param {CryptoKey} privateKey
     * @param {string} data
     * @returns {Promise<string>} - Base64 encoded signature
     */
    signData: async function(privateKey, data) {
        const encoder = new TextEncoder();
        const encodedData = encoder.encode(data);
        const signature = await window.crypto.subtle.sign(
            {
                name: "ECDSA",
                hash: { name: "SHA-256" },
            },
            privateKey,
            encodedData
        );
        return this.arrayBufferToBase64(signature);
    },

    /**
     * Helper to convert ArrayBuffer to Base64.
     * @param {ArrayBuffer} buffer
     * @returns {string}
     */
    arrayBufferToBase64: function(buffer) {
        let binary = '';
        const bytes = new Uint8Array(buffer);
        const len = bytes.byteLength;
        for (let i = 0; i < len; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    },

    /**
     * Helper to convert Base64 to ArrayBuffer.
     * @param {string} base64
     * @returns {ArrayBuffer}
     */
    base64ToArrayBuffer: function(base64) {
        const binary_string = window.atob(base64);
        const len = binary_string.length;
        const bytes = new Uint8Array(len);
        for (let i = 0; i < len; i++) {
            bytes[i] = binary_string.charCodeAt(i);
        }
        return bytes.buffer;
    }
};

document.addEventListener('DOMContentLoaded', () => {
    const generateBtn = document.getElementById('paas-generate-keys');
    const signBtn = document.getElementById('paas-sign-manifest');
    const submitBtn = document.getElementById('paas-submit-signature');

    const pubKeyText = document.getElementById('paas-public-key');
    const privKeyText = document.getElementById('paas-private-key');
    const manifestText = document.getElementById('paas-manifest-to-sign');
    const signatureText = document.getElementById('paas-signature');
    const statusText = document.getElementById('paas-submit-status');

    let keyPair = null;

    if (generateBtn) {
        generateBtn.addEventListener('click', async () => {
            keyPair = await paasCrypto.generateKeyPair();
            pubKeyText.value = await paasCrypto.exportKeyToPem('public', keyPair.publicKey);
            privKeyText.value = await paasCrypto.exportKeyToPem('private', keyPair.privateKey);
        });
    }

    if (signBtn) {
        signBtn.addEventListener('click', async () => {
            const manifest = manifestText.value;
            const privateKeyPem = privKeyText.value;
            if (!manifest || !privateKeyPem) {
                alert('Please generate keys and make sure manifest is available.');
                return;
            }

            try {
                const privateKey = await paasCrypto.importPrivateKey(privateKeyPem);
                const signature = await paasCrypto.signData(privateKey, manifest);
                signatureText.value = signature;
            } catch (e) {
                alert('Failed to sign manifest: ' + e.message);
            }
        });
    }

    if (submitBtn) {
        submitBtn.addEventListener('click', async () => {
            const postId = new URLSearchParams(window.location.search).get('post');
            const manifest = manifestText.value;
            const signature = signatureText.value;
            const pubkey = pubKeyText.value;

            if (!postId || !manifest || !signature || !pubkey) {
                alert('Missing data for submission.');
                return;
            }

            statusText.textContent = 'Submitting...';

            try {
                const response = await fetch('/wp-json/provenance/v1/signed-manifest', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        // Assuming a nonce is available for logged-in users
                        // 'X-WP-Nonce': wpApiSettings.nonce 
                    },
                    body: JSON.stringify({
                        post_id: parseInt(postId, 10),
                        manifest: manifest,
                        signature: signature,
                        pubkey_pem: pubkey
                    })
                });

                const result = await response.json();

                if (response.ok) {
                    statusText.textContent = `Success! Manifest ID: ${result.manifest_id}`;
                } else {
                    statusText.textContent = `Error: ${result.message}`;
                }
            } catch (e) {
                statusText.textContent = `Request failed: ${e.message}`;
            }
        });
    }
});