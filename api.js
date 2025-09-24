// api/verify.js
export default async function handler(req, res) {
    // Enable CORS
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    
    if (req.method === 'OPTIONS') {
        res.status(200).end();
        return;
    }

    const { key } = req.query;
    
    if (!key) {
        return res.status(400).json({ 
            valid: false, 
            error: 'Chave não fornecida' 
        });
    }

    try {
        // Fetch keys from GitHub
        const GITHUB_TOKEN = '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';
        const REPO_OWNER = process.env.GITHUB_OWNER || 'seu_usuario_github'; // Configure no Vercel
        const REPO_NAME = process.env.GITHUB_REPO || 'seu_repositorio'; // Configure no Vercel
        const FILE_PATH = 'soijoi4j3iou45lkfjvosdaio4jm5io3ljmgiofjsdfdfjl4k3mgfdjgjeio3iojgfdk.json';
        const XOR_KEY = 0x48953;

        const response = await fetch(`https://api.github.com/repos/${REPO_OWNER}/${REPO_NAME}/contents/${FILE_PATH}`, {
            headers: {
                'Authorization': `token ${GITHUB_TOKEN}`,
                'Accept': 'application/vnd.github.v3+json'
            }
        });

        if (!response.ok) {
            return res.status(500).json({ 
                valid: false, 
                error: 'Erro ao acessar dados' 
            });
        }

        const data = await response.json();
        
        // Decrypt data
        const encryptedData = Buffer.from(data.content, 'base64').toString();
        const decryptedData = xorCrypt(encryptedData, XOR_KEY);
        const keysData = JSON.parse(decryptedData);

        // Find key
        const keyData = keysData.keys.find(k => k.key === key);
        
        if (!keyData) {
            return res.status(404).json({ 
                valid: false, 
                error: 'Chave não encontrada' 
            });
        }

        // Check if key is activated and not expired
        if (!keyData.activated) {
            return res.status(401).json({ 
                valid: false, 
                error: 'Chave não ativada' 
            });
        }

        const now = new Date();
        const expirationDate = new Date(keyData.expiresAt);

        if (expirationDate < now) {
            return res.status(401).json({ 
                valid: false, 
                error: 'Chave expirada' 
            });
        }

        // Key is valid
        const remainingTime = expirationDate - now;
        const remainingDays = Math.ceil(remainingTime / (1000 * 60 * 60 * 24));

        return res.status(200).json({
            valid: true,
            expires: keyData.expiresAt,
            remainingDays: remainingDays,
            activatedAt: keyData.activatedAt,
            duration: keyData.duration
        });

    } catch (error) {
        console.error('Verification error:', error);
        return res.status(500).json({ 
            valid: false, 
            error: 'Erro interno do servidor' 
        });
    }
}

// XOR function for decryption
function xorCrypt(text, key) {
    let result = '';
    for (let i = 0; i < text.length; i++) {
        result += String.fromCharCode(text.charCodeAt(i) ^ (key & 0xFF));
    }
    return result;
}
