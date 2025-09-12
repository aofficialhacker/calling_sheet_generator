import sys
import json
import pathlib
import re
from google import genai
from google.genai import types
from PIL import Image
import cv2
import numpy as np
import os
from enhanced_image_preprocessor import EnhancedImagePreprocessor

def preprocess_image_enhanced(input_path: pathlib.Path) -> pathlib.Path | None:
    """Enhanced preprocessing using the new preprocessor"""
    try:
        processor = EnhancedImagePreprocessor()
        enhanced_path = processor.process_image(input_path)
        return enhanced_path
    except Exception as e:
        print(f"Enhanced preprocessing failed, falling back to basic: {e}", file=sys.stderr)
        # Fallback to basic preprocessing
        return preprocess_image_basic(input_path)

def preprocess_image_basic(input_path: pathlib.Path) -> pathlib.Path | None:
    """Fallback basic preprocessing (original method)"""
    try:
        image = cv2.imread(str(input_path))
        if image is None:
            return None
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        denoised = cv2.fastNlMeansDenoising(gray, None, h=10, templateWindowSize=7, searchWindowSize=21)
        binary_image = cv2.adaptiveThreshold(
            denoised, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
            cv2.THRESH_BINARY, 11, 2
        )
        output_path = input_path.parent / f"preprocessed_{input_path.name}"
        cv2.imwrite(str(output_path), binary_image)
        return output_path
    except Exception:
        return input_path

def create_enhanced_prompt():
    """Enhanced prompt specifically designed for calling sheet OCR"""
    return """
You are a specialized Optical Mark Recognition (OMR) and Optical Character Recognition (OCR) expert system designed specifically for analyzing calling sheets taken with mobile phone cameras.

**Image Analysis Context:**
- This image is a calling sheet photographed with a mobile phone
- Contains printed record IDs and handwritten marks by telecallers
- May have slight perspective distortion, lighting variations, or shadows
- Handwritten marks include: circled numbers, pen marks, and digits

**Your Analysis Task:**
Analyze this calling sheet image with extreme precision. The sheet contains these columns:
1. **ID Column**: Long alphanumeric record IDs (like MIV01B00100001, LIV01B00100002, etc.)
2. **Slot Column**: Single digits (1-8) written by hand
3. **Disposition Column**: Multiple choice circles with numbers 11-24. Look for FILLED/DARKENED circles
4. **Follow Day Column**: Single digits (1-9) written by hand for follow-up days  
5. **Follow Slot Column**: Single digits (1-8) written by hand for follow-up time slots

**Step-by-Step Analysis Protocol:**

1. **SCAN FOR RECORD IDs**: 
   - Look for alphanumeric strings starting with letters (like MIV, LIV, etc.)
   - These are usually 13-15 characters long
   - Read each ID completely and accurately
   - This becomes your "record_id" anchor for each row

2. **IDENTIFY HANDWRITTEN SLOT NUMBERS**:
   - In the Slot column, look for handwritten single digits (1-8)
   - These may be written with pen/pencil in various handwriting styles
   - If no number is written or visible, mark as blank

3. **DETECT FILLED CIRCLES FOR DISPOSITION**:
   - In the Disposition column, look for multiple circles with numbers next to them
   - Identify which circle is FILLED, DARKENED, or MARKED
   - Read the 2-digit number (11-24) next to the filled circle
   - Ignore empty/unfilled circles
   - If no circle is clearly marked, leave blank

4. **READ FOLLOW-UP HANDWRITTEN NUMBERS**:
   - Follow Day: Look for handwritten single digits (1-9) 
   - Follow Slot: Look for handwritten single digits (1-8)
   - These may be faint or in different ink colors
   - If nothing is written, mark as blank

**Recognition Tips for Mobile Photos:**
- Account for slight shadows and lighting variations
- Handwritten numbers may vary in thickness and darkness
- Some marks might be partially visible due to perspective
- Focus on clear, intentional marks vs accidental marks
- Filled circles may appear as solid dots or heavy dark circles

**Output Format (STRICT):**
Your response MUST be ONLY raw CSV data with this exact header:
`record_id,slot,disposition_code,follow_day,follow_slot`

**Output Rules:**
1. Include every record ID you can read, even if other fields are blank
2. Use blank values (not null/none) for missing data
3. No explanations, markdown, or additional text
4. Example of correct format:

record_id,slot,disposition_code,follow_day,follow_slot
MIV01B00100001,1,24,3,4
MIV01B00100002,4,,,
MIV01B00100003,3,22,1,
MIV01B00100004,2,15,2,7

**Quality Verification:**
Before outputting, verify:
- Every line has exactly 5 comma-separated values
- Record IDs are complete alphanumeric strings
- Disposition codes are 2-digit numbers (11-24) only when clearly marked
- Slot numbers are single digits (1-8) only
- Follow numbers are single digits as specified

Begin analysis now and output ONLY the CSV data:
"""

def main():
    # API key configuration
    api_key = "AIzaSyDD1HTQWJNbrzFxwH5YWIHbnmcx9-FD_4s"
    if not api_key or api_key == 'YOUR_API_KEY':
        print(json.dumps({"error": "API key not set. Set GEMINI_API_KEY environment variable."}))
        sys.exit(1)

    if len(sys.argv) < 2:
        print(json.dumps({"error": "No file path provided."}))
        sys.exit(1)

    image_path = pathlib.Path(sys.argv[1])
    if not image_path.exists():
        print(json.dumps({"error": f"File not found at: {sys.argv[1]}"}))
        sys.exit(1)

    # Try enhanced preprocessing first, fallback to basic if it fails
    use_enhanced = len(sys.argv) <= 2 or sys.argv[2] != "basic"
    
    if use_enhanced:
        preprocessed_path = preprocess_image_enhanced(image_path)
    else:
        preprocessed_path = preprocess_image_basic(image_path)
    
    if not preprocessed_path or not preprocessed_path.exists():
        print(json.dumps({"error": "Image preprocessing failed."}))
        sys.exit(1)

    try:
        # Use the enhanced prompt
        prompt = create_enhanced_prompt()
        
        with Image.open(preprocessed_path) as image:
            client = genai.Client(api_key=api_key)
            
            # Use Gemini 2.0 Flash for better accuracy
            response = client.models.generate_content(
                model="gemini-2.0-flash-exp",
                contents=[prompt, image],
                config=types.GenerateContentConfig(
                    thinking_config=types.ThinkingConfig(
                        thinking_budget=0  # disables "thinking"/reflection for faster response
                    ),
                    temperature=0.1,  # Lower temperature for more consistent results
                    max_output_tokens=4000,  # Sufficient for large call sheets
                    candidate_count=1
                ),
            )
            
        text = response.text
        
        # Enhanced CSV extraction with better regex
        csv_patterns = [
            r"```(?:csv)?\s*\n(.*?)\n```",  # Standard markdown code blocks
            r"```\s*(record_id,.*?)```",     # CSV starting with header
            r"(record_id,slot,disposition_code,follow_day,follow_slot.*?)(?:\n\n|\Z)"  # Direct CSV format
        ]
        
        cleaned_text = None
        for pattern in csv_patterns:
            csv_match = re.search(pattern, text, re.DOTALL | re.IGNORECASE)
            if csv_match:
                cleaned_text = csv_match.group(1).strip()
                break
        
        # If no pattern matched, try to extract CSV-like content
        if not cleaned_text:
            lines = text.strip().split('\n')
            # Look for header line
            header_idx = -1
            for i, line in enumerate(lines):
                if 'record_id' in line.lower() and 'slot' in line.lower():
                    header_idx = i
                    break
            
            if header_idx >= 0:
                cleaned_text = '\n'.join(lines[header_idx:])
            else:
                cleaned_text = text.strip()

        # Validate CSV format
        if cleaned_text and not cleaned_text.lower().startswith('record_id'):
            print(json.dumps({"error": f"AI response did not start with the expected CSV header 'record_id'. Response: {cleaned_text[:200]}..."}))
            sys.exit(1)

        # Additional validation - check for proper CSV structure
        lines = cleaned_text.split('\n')
        if len(lines) < 2:  # Must have header + at least one data row
            print(json.dumps({"error": f"Insufficient CSV data. Only {len(lines)} lines found."}))
            sys.exit(1)

        print(cleaned_text)

    except Exception as e:
        error_msg = f"An error occurred during enhanced OCR processing: {str(e)}"
        print(json.dumps({"error": error_msg}))
        sys.exit(1)
    finally:
        # Cleanup preprocessed files
        if preprocessed_path and preprocessed_path.exists():
            try:
                preprocessed_path.unlink()
            except OSError as e:
                print(json.dumps({"warning": f"Could not remove temp file: {e}"}), file=sys.stderr)

if __name__ == "__main__":
    main()