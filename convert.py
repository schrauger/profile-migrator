#!/usr/bin/env python3
# coding: utf-8

import lxml.etree as etree

parser = etree.XMLParser(strip_cdata=False) #WP has CDATA xml tags

def parseXML(xmlfile):
  tree = etree.parse(xmlfile, parser=parser)
  root = tree.getroot()
  namespaces = {
    'wp' : 'http://wordpress.org/export/1.2/'
  }


  for item in root.findall('./channel/item'):
    single_profile = {}

    # change posttype from profiles to person
    item = change_cdata(item.find('wp:post_type', namespaces), 'profiles', 'person')

    # append order_by_name field
    fname = get_cdata_val(item, 'first_name', namespaces)
    mname = get_cdata_val(item, 'middle_initial', namespaces)
    lname = get_cdata_val(item, 'last_name', namespaces)

    fullname = ''
    sortname = ''
    if fname:
      fullname += fname
      sortname += fname
    if mname:
      fullname += ' ' + mname
      sortname += ' ' + mname
    if lname:
      fullname += ' ' + lname
      sortname = lname + ', ' + sortname

    item.append(cdata_element_to_append('person_orderby_name', sortname))
    item.append(cdata_element_to_append('_person_orderby_name', 'field_59669002f433d'))


    for child in item.findall('wp:postmeta/wp:meta_key', namespaces):

      # job title
      child = change_cdata(child, 'position', 'person_jobtitle')
      child = change_cdata(child, '_position', '_person_jobtitle')
      child = change_cdata(child, 'field_156', 'field_5953aa3d25c14')
  print(etree.tostring(root))

def get_cdata_val(element, name, namespaces):

  el = element.xpath('//wp:postmeta[wp:meta_key[text()="' + name + '"]/text()]/wp:meta_value/text()', namespaces = namespaces)
  if el:
    return el[0]
  else:
    return ""

def cdata_element_to_append(name, value):
  wp_prefix = '{http://wordpress.org/export/1.2/}'
  el_postmeta = etree.Element(wp_prefix + "postmeta")
  el_meta_key = etree.SubElement(el_postmeta, wp_prefix + "meta_key")
  el_meta_key.text = etree.CDATA(name)
  el_meta_value = etree.SubElement(el_postmeta, wp_prefix + "meta_value")
  el_meta_value.text = etree.CDATA(value)
  return el_postmeta



# returns an element with cdata text changed
def change_cdata(element, oldtext, newtext):
  print(element.text)
  if element.text.lower() == oldtext:
    element.text = newtext
  print(element.text)
  return element

#def change_tag(element, newtag):


with open('dump.xml') as xmldump:
  parseXML(xmldump)
