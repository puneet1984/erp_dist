from flask import Flask, request, jsonify
import requests
import json
import logging
import time
from datetime import datetime, timedelta

app = Flask(__name__)

# Global variable to track last failure time
_last_failure_time = None
_retry_interval = 300  # 5 minutes in seconds

def _check_endpoint_availability(url, username, password):
    try:
        response = requests.head(
            url,
            auth=(username, password),
            timeout=5
        )
        return response.status_code == 200
    except requests.exceptions.RequestException:
        return False

@app.route('/send-sms', methods=['POST'])
def handle_sms():
    try:
        data = request.get_json()
        if not data or 'message' not in data or 'phone_number' not in data:
            return jsonify({'status': 'error', 'error': 'Invalid request data'}), 400

        result = send_sms(data['message'], data['phone_number'])
        if result:
            return jsonify({'status': 'success'}), 200
        return jsonify({'status': 'error', 'error': 'SMS sending failed'}), 500

    except Exception as e:
        logging.error(f"API Error: {str(e)}")
        return jsonify({'status': 'error', 'error': str(e)}), 500

def send_sms(message, phone_number):
    global _last_failure_time
    
    # API credentials
    api_username = 'sms'
    api_password = 'hfjZLcDY'
    device_local_ip = '192.168.1.87'
    
    # API endpoint
    url = f"http://{device_local_ip}:8080/message"

    # Check if we're still in cooldown period after last failure
    if _last_failure_time:
        time_since_failure = (datetime.now() - _last_failure_time).total_seconds()
        if time_since_failure < _retry_interval:
            logging.warning(f"SMS endpoint is in cooldown period. {_retry_interval - time_since_failure:.0f} seconds remaining.")
            return False

    # Check endpoint availability
    if not _check_endpoint_availability(url, api_username, api_password):
        _last_failure_time = datetime.now()
        logging.error(f"SMS endpoint is not accessible. Will retry after {_retry_interval} seconds.")
        return False

    # Reset failure tracking if endpoint is available
    _last_failure_time = None

    # Request payload
    data = {
        "message": message,
        "phoneNumbers": [phone_number]
    }
    
    try:
        # Send POST request
        response = requests.post(
            url,
            auth=(api_username, api_password),
            headers={'Content-Type': 'application/json'},
            json=data
        )
        
        # Parse response
        response_data = response.json()
        
        # Check response status
        if response_data.get('status') == 'success':
            return True
        else:
            error_message = response_data.get('error', 'Unknown error')
            logging.error(f"SMS API Response Error: {error_message}")
            return False
            
    except requests.exceptions.RequestException as e:
        logging.error(f"SMS API Error: {str(e)}")
        return False
    except json.JSONDecodeError as e:
        logging.error(f"SMS API Response Parse Error: {str(e)}")
        return False

if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO)
    app.run(host='127.0.0.1', port=5000)
